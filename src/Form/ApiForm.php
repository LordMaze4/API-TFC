<?php
namespace Drupal\api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Formulario principal para interactuar con la API de Gmail desde Drupal.
 */
class ApiForm extends FormBase {

  // Constantes de configuración para la autenticación con Google.
  private const GOOGLE_CLIENT_ID = '915531853637-3cfsacis4p9melbs19268mlvmhrbraeq.apps.googleusercontent.com';
  private const GOOGLE_CLIENT_SECRET = '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';
  private const GOOGLE_REDIRECT_URI = 'https://my-drupal-site10.ddev.site/api/Form';
  private const GOOGLE_SCOPES = [
    'https://www.googleapis.com/auth/gmail.send',
    'https://www.googleapis.com/auth/gmail.readonly',
    'https://www.googleapis.com/auth/gmail.labels',
    'https://www.googleapis.com/auth/gmail.modify',
  ];

  /**
   * {@inheritdoc}
   * Devuelve el ID único del formulario.
   */
  public function getFormId(): string {
    return 'api_form';
  }

  /**
   * Construye el formulario principal, mostrando las acciones disponibles y el estado de autenticación.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = \Drupal::request();

    if ($request->query->get('code')) {
      $this->handleOAuthCallback();
    }

    $accessToken = \Drupal::service('session')->get('google_access_token');
    $form['status'] = [
      '#markup' => $accessToken
        ? '<p style="color: green;">✔ Autenticado con Google.</p>'
        : '<p style="color: red;">✖ No autenticado con Google. Por favor, haz clic en "Autentifícate con Google".</p>',
    ];

    $form['authenticate'] = [
      '#type' => 'link',
      '#title' => $this->t('Iniciar sesión con Google'),
      '#url' => Url::fromUri('https://accounts.google.com/o/oauth2/auth', [
        'query' => [
          'client_id' => self::GOOGLE_CLIENT_ID,
          'redirect_uri' => self::GOOGLE_REDIRECT_URI,
          'response_type' => 'code',
          'scope' => implode(' ', self::GOOGLE_SCOPES),
          'access_type' => 'offline',
          'prompt' => 'consent',
        ],
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    $selected_action = $form_state->get('selected_action') ?? 'send_email';
    $form['action_choice'] = [
      '#type' => 'radios',
      '#title' => $this->t('Selecciona una acción'),
      '#options' => [
        'send_email' => $this->t('Enviar Correo'),
        'read_emails' => $this->t('Leer y Buscar Correos'),
        'delete_emails' => $this->t('Eliminar Correos'),
      ],
      '#default_value' => $selected_action,
    ];

    $form['submit_action_choice'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continuar'),
      '#submit' => ['::submitActionChoice'],
    ];

    $form['actions'] = ['#type' => 'actions'];

    if ($selected_action === 'read_emails') {
      $this->buildSearchEmailsForm($form, $form_state);
      $this->buildReadEmailsForm($form, $form_state);
    } elseif ($selected_action === 'send_email') {
      $this->buildSendEmailForm($form, $form_state, $accessToken);
    } elseif ($selected_action === 'delete_emails') {
      $this->buildDeleteEmailsForm($form, $form_state, $accessToken);
    }

    // Mostrar resultados paginados de búsqueda
    if ($selected_action === 'read_emails' && $form_state->get('search_cache')) {
      $emails = $form_state->get('search_cache');
      $limit = 5;
      $pagerManager = \Drupal::service('pager.manager');
      $page = $pagerManager->findPage();
      $offset = $page * $limit;

      $items = array_slice($emails, $offset, $limit);

      $form['search_results'] = [
        '#type' => 'table',
        '#header' => [$this->t('Asunto'), $this->t('De'), $this->t('Fecha')],
        '#rows' => array_map(fn($email) => [
          $email['subject'] ?? $this->t('Sin asunto'),
          $email['from'] ?? $this->t('Desconocido'),
          $email['date'] ?? $this->t('Sin fecha'),
        ], $items),
        '#empty' => $this->t('No se encontraron correos.'),
      ];

      $form['pager'] = ['#type' => 'pager'];
    }

    return $form;
  }

  /**
   * Construye el formulario para leer y buscar correos.
   */
  private function buildReadEmailsForm(array &$form, FormStateInterface $form_state): void {
    $form['actions']['submit_read'] = [
      '#type' => 'submit',
      '#value' => $this->t('Ver Emails'),
      '#submit' => ['::submitReadEmails'],
    ];

    if ($form_state->get('show_emails')) {
      try {
        $emails = $this->getEmails();

        // Log para depuración: Verificar los datos obtenidos.
        \Drupal::logger('api')->debug('Correos obtenidos para lectura: <pre>@emails</pre>', ['@emails' => print_r($emails, TRUE)]);

        // Construir la tabla con los datos correctos.
        $form['emails'] = empty($emails)
          ? ['#markup' => '<p>' . $this->t('No se encontraron correos.') . '</p>']
          : [
            '#type' => 'table',
            '#header' => [$this->t('Asunto'), $this->t('De'), $this->t('Fecha')],
            '#rows' => array_map(fn($email) => [
              $email['subject'] ?? $this->t('Sin asunto'),
              $email['from'] ?? $this->t('Desconocido'),
              $email['date'] ?? $this->t('Sin fecha'),
            ], $emails),
          ];
      } catch (\Exception $e) {
        $form['emails'] = ['#markup' => '<p>' . $e->getMessage() . '</p>'];
      }
    }
  }

  /**
   * Construye el formulario para enviar correos.
   */
  private function buildSendEmailForm(array &$form, FormStateInterface $form_state, ?string $accessToken): void {
    $form['to'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Correo del destinatario'),
      '#description' => $this->t('Puedes ingresar múltiples direcciones separadas por comas.'),
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Asunto'),
    ];
    $form['body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cuerpo del mensaje'),
    ];
    $form['save_as_draft'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Guardar como borrador'),
    ];

    $labels = [];
    if ($accessToken) {
      $gmailService = \Drupal::service('api.gmail_service');
      $gmailLabels = $gmailService->getLabels($accessToken);
      foreach ($gmailLabels as $label) {
        $labels[$label['id']] = $label['name'];
      }
    }

    $form['label'] = [
      '#type' => 'select',
      '#title' => $this->t('Etiqueta'),
      '#options' => $labels,
      '#empty_option' => $this->t('- Sin etiqueta -'),
    ];

    $form['attachments'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Adjuntar archivos'),
      '#upload_location' => 'public://email_attachments/',
      '#multiple' => TRUE,
      '#description' => $this->t('Selecciona uno o más archivos para adjuntar al correo.'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enviar Correo'),
    ];
  }

  /**
   * Construye el formulario para eliminar correos, mostrando una tabla con los correos y casillas para seleccionar.
   */
  private function buildDeleteEmailsForm(array &$form, FormStateInterface $form_state, ?string $accessToken): void {
    if (!$accessToken) {
      $form['list_emails'] = ['#markup' => '<p>' . $this->t('No autenticado con Google.') . '</p>'];
      return;
    }

    try {
      $gmailService = \Drupal::service('api.gmail_service');
      $emails = $gmailService->getEmails($accessToken);

      if (empty($emails)) {
        $form['list_emails'] = ['#markup' => '<p>' . $this->t('No se encontraron correos.') . '</p>'];
      } else {
        $form['emails_to_delete'] = [
          '#type' => 'table',
          '#header' => [$this->t('Seleccionar'), $this->t('De'), $this->t('Asunto'), $this->t('Fecha')],
          '#rows' => array_map(fn($email) => [
            [
              'data' => [
                '#type' => 'checkbox',
                '#return_value' => $email['id'],
                '#name' => 'emails_to_delete[]',
              ],
            ],
            $email['from'] ?? $this->t('Desconocido'),
            $email['subject'] ?? $this->t('Sin asunto'),
            $email['date'] ?? $this->t('Sin fecha'),
          ], $emails),
          '#empty' => $this->t('No se encontraron correos.'),
        ];

        $form['actions']['delete_selected'] = [
          '#type' => 'submit',
          '#value' => $this->t('Eliminar correos seleccionados'),
          '#submit' => ['::submitDeleteSelectedEmails'],
        ];
      }
    } catch (\Exception $e) {
      $form['list_emails'] = ['#markup' => '<p>' . $this->t('Error al listar correos: @message', ['@message' => $e->getMessage()]) . '</p>'];
    }
  }

  /**
   * Construye el formulario de búsqueda de correos.
   */
  private function buildSearchEmailsForm(array &$form, FormStateInterface $form_state): void {
    $labels = $this->getAvailableLabels();
    $form['search_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Buscar correos'),
      '#open' => TRUE,
    ];

    $form['search_section']['search_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texto a buscar'),
      '#default_value' => '',
    ];

    $form['search_section']['label_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Filtrar por etiqueta'),
      '#options' => ['' => $this->t('- Cualquiera -')] + $labels,
    ];

    $form['search_section']['search_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Buscar'),
      '#submit' => ['::submitSearchEmails'],
    ];
  }

  /**
   * Maneja el cambio de acción seleccionada en el formulario.
   */
  public function submitActionChoice(array &$form, FormStateInterface $form_state): void {
    $form_state->set('selected_action', $form_state->getValue('action_choice'));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Envía el formulario de envío de correo o guardado de borrador.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $gmailService = \Drupal::service('api.gmail_service');

    try {
      $accessToken = $this->getAccessToken();
    } catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Error al obtener el token de acceso: @message', ['@message' => $e->getMessage()]));
      return;
    }

    $to = $form_state->getValue('to');
    $subject = $form_state->getValue('subject');
    $body = $form_state->getValue('body');
    $saveAsDraft = $form_state->getValue('save_as_draft');
    $labelId = $form_state->getValue('label');
    $attachmentsFids = $form_state->getValue('attachments');
    $attachmentsPaths = [];

    if (!empty($attachmentsFids)) {
      foreach ($attachmentsFids as $fid) {
        $file = \Drupal\file\Entity\File::load($fid);
        if ($file) {
          $file->setPermanent();
          $file->save();
          $attachmentsPaths[] = $file->getFileUri();
        }
      }
    }

    if (empty($to)) {
      \Drupal::messenger()->addError($this->t('El correo del destinatario es obligatorio.'));
      return;
    }

    try {
      if ($saveAsDraft) {
        $gmailService->saveDraft($accessToken, $to, $subject, $body, $attachmentsPaths);
        \Drupal::messenger()->addMessage($this->t('Correo guardado como borrador.'));
      } else {
        $gmailService->sendEmail($accessToken, $to, $subject, $body, $attachmentsPaths);
        \Drupal::messenger()->addMessage($this->t('Correo enviado correctamente.'));
      }
    } catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Error: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Muestra los correos al pulsar "Ver Emails" en el apartado de lectura.
   */
  public function submitReadEmails(array &$form, FormStateInterface $form_state): void {
    $form_state->set('show_emails', TRUE);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Elimina los correos seleccionados en el apartado de eliminar correos.
   */
  public function submitDeleteEmails(array &$form, FormStateInterface $form_state): void {
    $emailsToDelete = $form_state->getValue('emails_to_delete') ?? [];

    if (!is_array($emailsToDelete)) {
      $emailsToDelete = [];
    }

    $selectedEmails = array_filter($emailsToDelete);
    if (empty($selectedEmails)) {
      \Drupal::messenger()->addError($this->t('No seleccionaste ningún correo para eliminar.'));
      return;
    }

    // Registrar los IDs de los correos seleccionados para depuración.
    \Drupal::logger('api')->debug('IDs de correos seleccionados para eliminar: <pre>@ids</pre>', ['@ids' => print_r($selectedEmails, TRUE)]);

    try {
      $accessToken = $this->getAccessToken();
      $gmailService = \Drupal::service('api.gmail_service');

      foreach ($selectedEmails as $emailId) {
        if (empty($emailId)) {
          \Drupal::messenger()->addError($this->t('Uno de los correos seleccionados no tiene un ID válido.'));
          continue;
        }
        $gmailService->deleteEmail($accessToken, $emailId);
      }

      \Drupal::messenger()->addMessage($this->t('Los correos seleccionados se eliminaron correctamente.'));
    } catch (\Exception $e) {
      // Registrar el error en los logs de Drupal.
      \Drupal::logger('api')->error('Error al eliminar correos: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Error al eliminar correos: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Realiza la búsqueda de correos según los filtros introducidos.
   */
  public function submitSearchEmails(array &$form, FormStateInterface $form_state): void {
    $query = $form_state->getValue('search_text');
    $label = $form_state->getValue('label_filter');
    $accessToken = $this->getAccessToken();
    $gmailService = \Drupal::service('api.gmail_service');

    try {
      $results = $gmailService->searchEmails($accessToken, $query, $label);
      $form_state->set('search_cache', $results);
      $form_state->setRebuild(TRUE);
    } catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Error al buscar correos: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Elimina los correos seleccionados en la tabla de eliminar correos.
   */
  public function submitDeleteSelectedEmails(array &$form, FormStateInterface $form_state): void {
    $emailsToDelete = $form_state->getValue('emails_to_delete') ?? [];

    if (!is_array($emailsToDelete)) {
      $emailsToDelete = [];
    }

    $selectedEmails = array_filter($emailsToDelete);
    if (empty($selectedEmails)) {
      \Drupal::messenger()->addError($this->t('No seleccionaste ningún correo para eliminar.'));
      return;
    }

    try {
      $accessToken = $this->getAccessToken();
      $gmailService = \Drupal::service('api.gmail_service');

      foreach ($selectedEmails as $emailId) {
        if (empty($emailId)) {
          \Drupal::messenger()->addError($this->t('Uno de los correos seleccionados no tiene un ID válido.'));
          continue;
        }
        $gmailService->deleteEmail($accessToken, $emailId);
      }

      \Drupal::messenger()->addMessage($this->t('Los correos seleccionados se eliminaron correctamente.'));
      $form_state->setRebuild(TRUE);
    } catch (\Exception $e) {
      \Drupal::logger('api')->error('Error al eliminar correos: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Error al eliminar correos: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Obtiene las etiquetas disponibles de Gmail.
   */
  private function getAvailableLabels(): array {
    $accessToken = $this->getAccessToken();
    $gmailService = \Drupal::service('api.gmail_service');
    $labels = $gmailService->getLabels($accessToken);

    $availableLabels = [];
    foreach ($labels as $label) {
      $availableLabels[$label['id']] = $label['name'];
    }

    return $availableLabels;
  }

  /**
   * Obtiene los correos del usuario autenticado.
   */
  private function getEmails(): array {
    $accessToken = $this->getAccessToken();
    $gmailService = \Drupal::service('api.gmail_service');
    return $gmailService->getEmails($accessToken);
  }

  /**
   * Obtiene el token de acceso almacenado en sesión.
   */
  private function getAccessToken(): string {
    $accessToken = \Drupal::service('session')->get('google_access_token');
    if (!$accessToken) {
      throw new \Exception($this->t('No autenticado con Google. Por favor, haz clic en "Autentifícate con Google".'));
    }
    return $accessToken;
  }

  /**
   * Maneja el callback de OAuth para obtener el token de acceso de Google.
   */
  private function handleOAuthCallback(): void {
    $code = \Drupal::request()->query->get('code');
    if ($code) {
      $data = [
        'code' => $code,
        'client_id' => self::GOOGLE_CLIENT_ID,
        'client_secret' => self::GOOGLE_CLIENT_SECRET,
        'redirect_uri' => self::GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
      ];

      $httpClient = \Drupal::service('http_client');
      $url = 'https://oauth2.googleapis.com/token';

      try {
        $response = $httpClient->post($url, ['form_params' => $data]);
        $responseData = json_decode($response->getBody(), TRUE);
        if (isset($responseData['access_token'])) {
          \Drupal::service('session')->set('google_access_token', $responseData['access_token']);
          \Drupal::messenger()->addMessage($this->t('Autenticación exitosa.'));
        } else {
          throw new \Exception($this->t('Error al obtener el token de acceso.'));
        }
      } catch (\Exception $e) {
        \Drupal::messenger()->addError($this->t('Error al procesar la autenticación: @message', ['@message' => $e->getMessage()]));
      }
    }
  }

  /**
   * Construye la paginación para los resultados de búsqueda.
   */
  private function buildPagination(array &$form, int $currentPage, int $totalPages): void {
    $form['pagination'] = [
      '#type' => 'pager',
      '#total_pages' => $totalPages,
      '#current_page' => $currentPage,
    ];
  }

  /**
   * Valida los campos del formulario antes de enviar.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected_action = $form_state->get('selected_action');

    if ($selected_action === 'send_email') {
      $to = $form_state->getValue('to');
      if (empty($to)) {
        $form_state->setErrorByName('to', $this->t('El campo "Correo del destinatario" es obligatorio.'));
      }

      $subject = $form_state->getValue('subject');
      if (empty($subject)) {
        $form_state->setErrorByName('subject', $this->t('El campo "Asunto" es obligatorio.'));
      }

      // Eliminamos la validación del campo "body".
    }
  }
}
