<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    public $resseler;
    public $client;
    public $cFullName;
    public $cr;
    public $et;
    public array $templateData;

    /**
     * @return array
     */
    public function doOperation(): array
    {
        $data = (array) $this->getRequest('data');
        $this->validateData($data);

        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];

        $resellerId = (int) $data['resellerId'];
        if (empty($resellerId)) {
            $result['notificationClientBySms']['message'] = 'Empty resellerId';

            return $result;
        }

        $notificationType = (int) $data['notificationType'];
        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            // предполагаю, что метод "__()" принимает 3 параметра и в зависимости от первого параметра
            // возвращает структуру данных для шаблона
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                    'FROM' => Status::getName((int)$data['differences']['from']),
                    'TO'   => Status::getName((int)$data['differences']['to']),
                ], $resellerId);
        }

        $emailFrom = getResellerEmailFrom($resellerId);
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                           'emailFrom' => $emailFrom,
                           'emailTo'   => $email,
                           'subject'   => __('complaintEmployeeEmailSubject', $this->templateData, $resellerId),
                           'message'   => __('complaintEmployeeEmailBody', $this->templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;

            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($this->client->getEmail())) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                           'emailFrom' => $emailFrom,
                           'emailTo'   => $client->getEmail(),
                           'subject'   => __('complaintClientEmailSubject', $this->templateData, $resellerId),
                           'message'   => __('complaintClientEmailBody', $this->templateData, $resellerId),
                    ],
                ], $resellerId, $this->client->getId(), NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($this->client->getMobile())) {
                $res = NotificationManager::send($resellerId, $this->client->getId(), NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $this->templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }

    /**
     * @param array $data
     */
    public function validateData(array $data)
    {
        if (empty((int) $data['notificationType'])) {
            $this->generate400Exception('Empty notificationType');
        }

        $this->reseller = Seller::getById((int) $data['resellerId']);
        if ($this->reseller === null) {
            $this->generate400Exception('Seller not found!');
        }

        $this->client = Contractor::getById((int) $data['clientId']);
        if ($this->client === null || $this->client->type !== Contractor::TYPE_CUSTOMER || $this->client->Seller->id !== $resellerId) {
            $this->generate400Exception('сlient not found!');
        }

        $this->cFullName = $this->client->getFullName();
        if (empty($this->client->getFullName())) {
            $this->cFullName = $this->client->getName();
        }

        $this->cr = Employee::getById((int) $data['creatorId']);
        if ($this->cr === null) {
            $this->generate400Exception('Creator not found!');
        }

        $this->et = Employee::getById((int) $data['expertId']);
        if ($this->et === null) {
            $this->generate400Exception('Expert not found!');
        }

        $this->templateData = [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => (int)$data['creatorId'],
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => (int)$data['expertId'],
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => (int)$data['clientId'],
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($this->templateData as $key => $val) {
            if (empty($val)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    /**
     * @param string $text
     * @throws \Exception
     */
    public function generate400Exception(string $text): void
    {
        throw new \Exception($text, 400);
    }
}
