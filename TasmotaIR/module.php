<?php

declare (strict_types = 1);

require_once __DIR__ . '/../libs/TasmotaService.php';
require_once __DIR__ . '/../libs/helper.php';

class Tasmota extends TasmotaService {
    use BufferHelper;

    public function Create() {
        //Never delete this line!
        parent::Create();
        $this->BufferResponse = '';
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');

        $this->createVariablenProfiles();
        //Anzahl die in der Konfirgurationsform angezeigt wird - Hier Standard auf 1
        $this->RegisterPropertyString('Topic', '');

        $this->RegisterPropertyString('FullTopic', '%prefix%/%topic%');
        $this->RegisterPropertyInteger('PowerOnState', 3);
        $this->RegisterPropertyInteger('GatewayMode', 0);
        $this->RegisterPropertyBoolean('MessageRetain', false);
        $this->RegisterVariableFloat('Tasmota_RSSI', 'RSSI');
        $this->RegisterVariableBoolean('Tasmota_DeviceStatus', 'Status', 'Tasmota.DeviceStatus');
        //Settings
        $this->RegisterPropertyBoolean('SystemVariables', false);

    }

    public function ApplyChanges() {
        //Never delete this line!
        parent::ApplyChanges();
        $this->BufferResponse = '';

        //Setze Filter fÃ¼r ReceiveData
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->setPowerOnState($this->ReadPropertyInteger('PowerOnState'));
        }

        $this->SendDebug(__FUNCTION__ . ' FullTopic', $this->ReadPropertyString('FullTopic'), 0);
        $topic = $this->FilterFullTopicReceiveData();
        $this->SendDebug(__FUNCTION__ . ' Filter FullTopic', $topic, 0);

        $this->SetReceiveDataFilter('.*' . $topic . '.*');
    }

    public function ReceiveData($JSONString) {
        $this->SendDebug('JSON', $JSONString, 0);
        if (!empty($this->ReadPropertyString('Topic'))) {
            $data = json_decode($JSONString);

            switch ($data->DataID) {
            case '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}': // MQTT Server
                $Buffer = $data;
                break;
            case '{DBDA9DF7-5D04-F49D-370A-2B9153D00D9B}': //MQTT Client
                $Buffer = json_decode($data->Buffer);
                break;
            default:
                $this->LogMessage('Invalid Parent', KL_ERROR);
                return;
            }
            $off = $this->ReadPropertyString('Off');
            $on = $this->ReadPropertyString('On');

            //PowerOnState Vairablen setzen
            if (fnmatch('*PowerOnState*', $Buffer->Payload)) {
                $this->SendDebug('PowerOnState Topic', $Buffer->Topic, 0);
                $this->SendDebug('PowerOnState Payload', $Buffer->Payload, 0);
                $Payload = json_decode($Buffer->Payload);
                if (property_exists($Payload, 'PowerOnState')) {
                    $this->setPowerOnStateInForm($Payload->PowerOnState);
                }
            }
            //Power Vairablen checken
            if (property_exists($Buffer, 'Topic')) {

                //State checken
                if (fnmatch('*STATE', $Buffer->Topic)) {
                    $myBuffer = json_decode($Buffer->Payload);
                    $this->SendDebug('State Payload', $Buffer->Payload, 0);
                    $this->SendDebug('State Wifi', $myBuffer->Wifi->RSSI, 0);

                    if ($this->ReadPropertyBoolean('SystemVariables')) {
                        $this->getSystemVariables($myBuffer);
                    }

                    SetValue($this->GetIDForIdent('Tasmota_RSSI'), $myBuffer->Wifi->RSSI);
                }
                if (fnmatch('*RESULT', $Buffer->Topic)) {
                    $this->SendDebug('Result Payload', $Buffer->Payload, 0);
                    $this->BufferResponse = $Buffer->Payload;
                    $Payload = json_decode($Buffer->Payload);

                    if (fnmatch('*MCP230XX_INT*', $Buffer->Payload)) {
                        $this->SendDebug('Sensor Payload', $Buffer->Payload, 0);
                        $this->SendDebug('Sensor Topic', $Buffer->Topic, 0);
                        for ($i = 0; $i <= 15; $i++) {
                            if (property_exists($Payload->MCP230XX_INT, 'D' . $i)) {
                                $this->RegisterVariableBoolean('Tasmota_MCP230XX_INT_D' . $i, 'MCP230XX_INT D' . $i, '', 0);
                                SetValue($this->GetIDForIdent('Tasmota_MCP230XX_INT_D' . $i), $Payload->MCP230XX_INT->{'D' . $i});

                                //MS
                                $this->RegisterVariableInteger('Tasmota_MCP230XX_INT_D' . $i . '_MS', 'MCP230XX_INT D' . $i . ' MS', '', 0);
                                SetValue($this->GetIDForIdent('Tasmota_MCP230XX_INT_D' . $i . '_MS'), $Payload->MCP230XX_INT->MS);
                            }
                        }
                    }
                    if (fnmatch('*S29cmnd_D*', $Buffer->Payload)) {
                        $this->SendDebug('Sensor Payload', $Buffer->Payload, 0);
                        $this->SendDebug('Sensor Topic', $Buffer->Topic, 0);
                        for ($i = 0; $i <= 15; $i++) {
                            if (property_exists($Payload, 'S29cmnd_D' . $i)) {
                                if (property_exists($Payload->{'S29cmnd_D' . $i}, 'STATE')) {
                                    $this->RegisterVariableBoolean('Tasmota_S29cmnd_D' . $i, 'S29cmnd D' . $i, '', 0);
                                    $this->EnableAction('Tasmota_S29cmnd_D' . $i);
                                    switch ($Payload->{'S29cmnd_D' . $i}->STATE) {
                                    case 'ON':
                                        $value = true;
                                        break;
                                    case 'OFF':
                                        $value = false;
                                        break;
                                    }
                                    SetValue($this->GetIDForIdent('Tasmota_S29cmnd_D' . $i), $value);
                                }
                            }
                        }
                    }

                    if (property_exists($Payload, 'PCA9685')) {
                        $this->RegisterProfileInteger('Tasmota.PCA9685', 'Intensity', '', '%', 0, 4095, 1);
                        $this->RegisterVariableInteger('Tasmota_PCA9685_PWM' . $Payload->PCA9685->PIN, 'PWM' . $Payload->PCA9685->PIN, 'Tasmota.PCA9685', 0);
                        $this->EnableAction('Tasmota_PCA9685_PWM' . $Payload->PCA9685->PIN);
                        $this->SetValue('Tasmota_PCA9685_PWM' . $Payload->PCA9685->PIN, $Payload->PCA9685->PWM);
                    }
                }

            }

            //IrReceived
            if (fnmatch('*IrReceived*', $Buffer->Payload)) {
                $myBuffer = json_decode($Buffer->Payload);
                $this->SendDebug('IrReceived Payload', $Buffer->Payload, 0);
                if (property_exists($myBuffer->IrReceived, 'Protocol')) {
                    $this->RegisterVariableString('Tasmota_IRProtocol', $this->Translate('IR Protocol'), '', 0);
                    SetValue($this->GetIDForIdent('Tasmota_IRProtocol'), $myBuffer->IrReceived->Protocol);
                }
                if (property_exists($myBuffer->IrReceived, 'Bits')) {
                    $this->RegisterVariableString('Tasmota_IRBits', $this->Translate('IR Bits'), '', 0);
                    SetValue($this->GetIDForIdent('Tasmota_IRBits'), $myBuffer->IrReceived->Bits);
                }
                if (property_exists($myBuffer->IrReceived, 'Data')) {
                    $this->RegisterVariableString('Tasmota_IRData', $this->Translate('IR Data'), '', 0);
                    SetValue($this->GetIDForIdent('Tasmota_IRData'), $myBuffer->IrReceived->Data);
                }
            }

        }
    }

    public function RequestAction($Ident, $Value) {
        $this->SendDebug(__FUNCTION__ . ' Ident', $Ident, 0);
        $this->SendDebug(__FUNCTION__ . ' Value', $Value, 0);

        if (strlen($Ident) != 13) {
            $power = substr($Ident, 13);
        } else {
            $power = 0;
        }
        $result = $this->setPower(intval($power), $Value);
    }

    public function setFanSpeed(int $value) {
        $command = 'FanSpeed';
        $msg = strval($value);
        $this->MQTTCommand($command, $msg);
    }

    private function createVariablenProfiles() {
        //Online / Offline Profile

    }
}
