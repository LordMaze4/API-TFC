<?php
namespace Drupal\api\Service;

use GuzzleHttp\Exception\RequestException;

/**
 * Servicio para interactuar con la API de Gmail desde Drupal.
 */
class GmailService {

  /**
   * Construye el mensaje de correo en formato MIME, soportando adjuntos.
   */
  private function buildEmail(string $to, string $subject, string $body, array $attachmentPaths = [], bool $isHtml = false): string {
    $boundary = md5(time());
    $contentType = $isHtml ? 'text/html' : 'text/plain';

    // Permitir múltiples destinatarios separados por comas.
    $toHeader = implode(', ', array_map('trim', explode(',', $to)));

    // Encabezados del mensaje.
    $rawMessage = "To: $toHeader\r\n";
    $rawMessage .= "Subject: $subject\r\n";
    $rawMessage .= "MIME-Version: 1.0\r\n";
    $rawMessage .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";

    // Cuerpo del mensaje.
    $rawMessage .= "--$boundary\r\n";
    $rawMessage .= "Content-Type: $contentType; charset=UTF-8\r\n\r\n";
    $rawMessage .= "$body\r\n\r\n";

    // Archivos adjuntos (si existen).
    foreach ($attachmentPaths as $attachmentPath) {
      $fileContent = file_get_contents($attachmentPath);
      $fileName = basename($attachmentPath);
      $rawMessage .= "--$boundary\r\n";
      $rawMessage .= "Content-Type: application/octet-stream; name=\"$fileName\"\r\n";
      $rawMessage .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n";
      $rawMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
      $rawMessage .= chunk_split(base64_encode($fileContent)) . "\r\n\r\n";
    }

    // Finalizar el mensaje.
    $rawMessage .= "--$boundary--";

    // Codificar el mensaje en Base64 URL-safe.
    return rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');
  }

  /**
   * Envía un correo utilizando la API de Gmail.
   */
  public function sendEmail(string $accessToken, string $to, string $subject, string $body, array $attachmentPaths = []): array {
    $this->validateEmailParameters($to, $subject, $body);
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/gmail/v1/users/me/messages/send';
    $rawMessage = $this->buildEmail($to, $subject, $body, $attachmentPaths);

    try {
      $response = $httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'raw' => $rawMessage,
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    }
    catch (RequestException $e) {
      $this->handleApiError($e, 'enviar el correo');
      return []; // Return an empty array in case of an exception.
    }
  }

  /**
   * Guarda un borrador en Gmail.
   */
  public function saveDraft(string $accessToken, string $to, string $subject, ?string $body, array $attachmentPaths = []): array {
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/gmail/v1/users/me/drafts';
    $rawMessage = $this->buildEmail($to, $subject, $body ?? '', $attachmentPaths);

    try {
      $response = $httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'message' => [
            'raw' => $rawMessage,
          ],
        ],
      ]);

      return json_decode($response->getBody(), TRUE);
    } catch (RequestException $e) {
      $this->handleApiError($e, 'guardar el borrador');
      return []; // Return an empty array in case of an exception.
    }
  }

  /**
   * Obtiene los correos del usuario autenticado.
   */
  public function getEmails(string $accessToken, int $maxResults = 10): array {
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/gmail/v1/users/me/messages';

    try {
      $response = $httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
        'query' => [
          'maxResults' => $maxResults,
        ],
      ]);

      $emails = json_decode($response->getBody(), TRUE);

      // Log para depuración: Verificar la respuesta de la API.
      \Drupal::logger('api')->debug('Respuesta de la API de Gmail: <pre>@response</pre>', ['@response' => print_r($emails, TRUE)]);

      if (!isset($emails['messages'])) {
        throw new \Exception('La respuesta de la API no contiene correos.');
      }

      $emailsList = [];
      foreach ($emails['messages'] as $email) {
        $messageId = $email['id'];
        $messageDetails = $this->getMessageDetails($accessToken, $messageId);
        if ($messageDetails) {
          $emailsList[] = [
            'id' => $messageId, // Asegúrate de incluir el ID del correo.
            'subject' => $messageDetails['subject'] ?? 'No subject',
            'from' => $messageDetails['from'] ?? 'Unknown',
            'date' => $messageDetails['date'] ?? 'Unknown',
          ];
        }
      }

      return $emailsList;

    } catch (RequestException $e) {
      $this->handleApiError($e, 'obtener los correos');
    }

    // Return an empty array if no emails are found or an exception occurs.
    return [];
  }

  /**
   * Obtiene los detalles de un mensaje específico (asunto, remitente, fecha).
   */
  private function getMessageDetails(string $accessToken, string $messageId): array {
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/gmail/v1/users/me/messages/' . $messageId;

    try {
      $response = $httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $messageData = json_decode($response->getBody(), TRUE);
      $headers = $messageData['payload']['headers'];
      $messageDetails = [];

      foreach ($headers as $header) {
        if ($header['name'] === 'Subject') {
          $messageDetails['subject'] = $header['value'];
        } elseif ($header['name'] === 'From') {
          $messageDetails['from'] = $header['value'];
        } elseif ($header['name'] === 'Date') {
          $messageDetails['date'] = $header['value'];
        }
      }

      return $messageDetails;
    } catch (RequestException $e) {
      $this->handleApiError($e, 'obtener los detalles del mensaje');
      return []; // Return an empty array in case of an exception.
    }
  }

  /**
   * Obtiene las etiquetas de Gmail.
   */
  public function getLabels(string $accessToken): array {
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/gmail/v1/users/me/labels';

    try {
      $response = $httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      $labelsData = json_decode($response->getBody(), TRUE);

      // Validación y log para depuración
      if (!isset($labelsData['labels'])) {
        \Drupal::logger('api')->error('Respuesta inesperada al obtener etiquetas: <pre>@response</pre>', [
          '@response' => print_r($labelsData, TRUE),
        ]);
        return [];
      }

      return $labelsData['labels'];

    } catch (RequestException $e) {
      // Log detallado del error de la API
      $errorDetails = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
      \Drupal::logger('api')->error('Error al obtener las etiquetas de Gmail: @error', ['@error' => $errorDetails]);
      return [];
    }
  }

  /**
   * Verifica la validez del token de acceso.
   */
  public function checkAccessTokenValidity(string $accessToken): bool {
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/oauth2/v1/tokeninfo?access_token=' . $accessToken;

    try {
      $response = $httpClient->get($url);
      return $response->getStatusCode() === 200;
    } catch (RequestException $e) {
      return false;
    }
  }

  /**
   * Lanza una excepción si el token de acceso no es válido.
   */
  public function validateAccessToken(string $accessToken): void {
    $gmailService = \Drupal::service('api.gmail_service');
    if (!$gmailService->checkAccessTokenValidity($accessToken)) {
      throw new \Exception('El token de acceso no es válido o ha expirado.');
    }
  }

  /**
   * Busca correos en Gmail por texto y etiqueta.
   */
  public function searchEmails(string $accessToken, string $query = '', string $label = ''): array {
    $httpClient = \Drupal::service('http_client');
    $url = 'https://www.googleapis.com/gmail/v1/users/me/messages';

    try {
      $queryParams = [];
      if (!empty($query)) {
        $queryParams[] = $query;
      }
      if (!empty($label)) {
        $queryParams[] = "label:$label";
      }

      $response = $httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
        'query' => [
          'q' => implode(' ', $queryParams),
          'maxResults' => 50,
        ],
      ]);

      $emails = json_decode($response->getBody(), TRUE);

      // Log para depuración: Verificar la respuesta de la API.
      \Drupal::logger('api')->debug('Resultados de búsqueda de correos: <pre>@response</pre>', ['@response' => print_r($emails, TRUE)]);

      if (!isset($emails['messages'])) {
        return [];
      }

      $emailsList = [];
      foreach ($emails['messages'] as $email) {
        $messageId = $email['id'];
        $messageDetails = $this->getMessageDetails($accessToken, $messageId);
        if ($messageDetails) {
          $emailsList[] = [
            'id' => $messageId,
            'subject' => $messageDetails['subject'] ?? 'No subject',
            'from' => $messageDetails['from'] ?? 'Unknown',
            'date' => $messageDetails['date'] ?? 'Unknown',
          ];
        }
      }

      return $emailsList;

    } catch (RequestException $e) {
      $this->handleApiError($e, 'buscar correos');
      return []; // Return an empty array in case of an exception.
    }
  }

  /**
   * Elimina un correo en Gmail por su ID. Si falla, intenta moverlo a la papelera.
   */
  public function deleteEmail($accessToken, $messageId) {
    if (empty($messageId)) {
      \Drupal::logger('api')->error('El ID del mensaje está vacío.');
      throw new \Exception('El ID del mensaje no puede estar vacío.');
    }

    // URL de la API para eliminar el correo.
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId;

    try {
      // Realizamos la solicitud DELETE.
      $httpClient = \Drupal::httpClient();
      $response = $httpClient->delete($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);

      // Verificamos el código de estado.
      if ($response->getStatusCode() == 204) {
        \Drupal::logger('api')->info('Correo eliminado correctamente. ID: @id', ['@id' => $messageId]);
      } else {
        // Si no obtenemos un 204, logueamos más detalles de la respuesta.
        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();
        \Drupal::logger('api')->error('No se pudo eliminar el correo. Código: @statusCode, Respuesta: @responseBody', [
          '@statusCode' => $statusCode,
          '@responseBody' => $responseBody,
        ]);
        throw new \Exception('Fallo al eliminar el mensaje. Código: ' . $statusCode . ', Respuesta: ' . $responseBody);
      }
    } catch (\GuzzleHttp\Exception\RequestException $e) {
      // Si ocurre un error, logueamos el detalle.
      if ($e->hasResponse()) {
        $statusCode = $e->getResponse()->getStatusCode();
        $errorMessage = $e->getResponse()->getBody()->getContents();
        \Drupal::logger('api')->error('Error al intentar eliminar el correo. Código: @statusCode. Mensaje: @errorMessage', [
          '@statusCode' => $statusCode,
          '@errorMessage' => $errorMessage,
        ]);
      } else {
        \Drupal::logger('api')->error('Error desconocido al intentar eliminar el correo. Detalle: @error', ['@error' => $e->getMessage()]);
      }
      
      // Intentamos mover el correo a la papelera si la eliminación falla.
      try {
        $trashUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/trash';
        $trashResponse = $httpClient->post($trashUrl, [
          'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
          ],
        ]);
        // Verificamos si la respuesta es exitosa.
        if ($trashResponse->getStatusCode() == 200) {
          \Drupal::logger('api')->info('Correo movido a la papelera correctamente. ID: @id', ['@id' => $messageId]);
          return;
        } else {
          // Si no se mueve correctamente a la papelera, logueamos el error.
          $trashStatusCode = $trashResponse->getStatusCode();
          $trashResponseBody = $trashResponse->getBody()->getContents();
          \Drupal::logger('api')->error('No se pudo mover el correo a la papelera. Código: @trashStatusCode, Respuesta: @trashResponseBody', [
            '@trashStatusCode' => $trashStatusCode,
            '@trashResponseBody' => $trashResponseBody,
          ]);
          throw new \Exception('No se pudo mover el correo a la papelera.');
        }
      } catch (\Exception $trashException) {
        // Si falla al mover a la papelera, logueamos el error.
        \Drupal::logger('api')->error('Error al mover el correo a la papelera. Error: @error', ['@error' => $trashException->getMessage()]);
        throw new \Exception('Error al mover el correo a la papelera: ' . $trashException->getMessage());
      }
    }
  }

  /**
   * Lista los correos del usuario autenticado (solo IDs).
   */
  public function listEmails($accessToken) {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';
    
    try {
      // Hacemos la solicitud GET para obtener los mensajes.
      $httpClient = \Drupal::httpClient();
      $response = $httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
        ],
      ]);
      
      // Verificamos que la respuesta fue exitosa (código 200).
      if ($response->getStatusCode() == 200) {
        $responseData = json_decode($response->getBody()->getContents(), TRUE);
        
        // Si hay mensajes, los procesamos.
        if (isset($responseData['messages'])) {
          \Drupal::logger('api')->info('Se recuperaron @count correos.', ['@count' => count($responseData['messages'])]);
          return $responseData['messages'];
        } else {
          \Drupal::logger('api')->info('No se encontraron correos.');
          return [];
        }
      } else {
        // Si la respuesta no es 200, registramos el error.
        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();
        \Drupal::logger('api')->error('Error al obtener correos. Código: @statusCode, Respuesta: @responseBody', [
          '@statusCode' => $statusCode,
          '@responseBody' => $responseBody,
        ]);
        throw new \Exception('No se pudo obtener los correos.');
      }
    } catch (\Exception $e) {
      \Drupal::logger('api')->error('Error al intentar recuperar correos. Error: @error', ['@error' => $e->getMessage()]);
      throw new \Exception('Error al recuperar correos: ' . $e->getMessage());
    }
  }

  /**
   * Maneja y registra errores de la API de Gmail.
   */
  private function handleApiError(RequestException $e, string $action): void {
    $errorDetails = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
    \Drupal::logger('api')->error('Error al @action: @error', ['@action' => $action, '@error' => $errorDetails]);
    throw new \Exception("Error al $action. Verifica los logs para más detalles.");
  }

  /**
   * Valida los parámetros de un correo antes de enviarlo.
   */
  private function validateEmailParameters(string $to, string $subject, ?string $body): void {
    if (empty($to)) {
      throw new \InvalidArgumentException('El destinatario no puede estar vacío.');
    }
    if (empty($subject)) {
      throw new \InvalidArgumentException('El asunto no puede estar vacío.');
    }
    // Eliminamos la validación del cuerpo del mensaje.
  }
}