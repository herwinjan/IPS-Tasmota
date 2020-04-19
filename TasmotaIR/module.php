<?php

declare (strict_types = 1);

require_once __DIR__ . '/../libs/TasmotaService.php';
require_once __DIR__ . '/../libs/helper.php';

$modes = array(
    array(0, "Off", "", -1),
    array(1, "Auto", "", -1),
    array(2, "Cool", "", -1),
    array(3, "Heat", "", -1),
    array(4, "Dry", "", -1),
    array(5, "fan", "", -1),
);
$fanSpeeds = array(
    array(0, "Auto", "", -1),
    array(1, "Minimal", "", -1),
    array(2, "Low", "", -1),
    array(3, "Medium", "", -1),
    array(4, "High", "", -1),
    array(5, "Max", "", -1),
);
$swingVs = array(
    array(0, "Auto", "", -1),
    array(1, "Off", "", -1),
    array(2, "Min", "", -1),
    array(3, "Low", "", -1),
    array(4, "Middle", "", -1),
    array(5, "High", "", -1),
    array(6, "Highest", "", -1),
);
$swingHs = array(
    array(0, "Auto", "", -1),
    array(1, "Off", "", -1),
    array(2, "LeftMax", "", -1),
    array(3, "Left", "", -1),
    array(4, "Middle", "", -1),
    array(5, "Right", "", -1),
    array(6, "RightMax", "", -1),
    array(7, "Wide", "", -1),
);

class TasmotaIR extends TasmotaService {
    use BufferHelper;

    public function Create() {
        //Never delete this line!
        parent::Create();
        $this->BufferResponse = '';
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');

        $this->createVariablenProfiles();

        $this->RegisterVariableBoolean('TasmotaHVAC_Power', $this->Translate('Power'), 'TasmotaHVAC.Power', 1);
        $this->RegisterVariableInteger('TasmotaHVAC_Mode', $this->Translate('Mode'), 'TasmotaHVAC.Mode', 2);
        $this->RegisterVariableInteger('TasmotaHVAC_FanSpeed', $this->Translate('FanSpeed'), 'TasmotaHVAC.FanSpeed', 3);
        $this->RegisterVariableInteger('TasmotaHVAC_SwingV', $this->Translate('Swing Vertical'), 'TasmotaHVAC.SwingV', 4);
        $this->RegisterVariableInteger('TasmotaHVAC_SwingH', $this->Translate('Swing Horizonal'), 'TasmotaHVAC.SwingH', 5);
        $this->RegisterVariableBoolean('TasmotaHVAC_Quiet', $this->Translate('Quiet'), 'TasmotaHVAC.Quiet', 6);
        $this->RegisterVariableBoolean('TasmotaHVAC_Turbo', 'Turbo', 'TasmotaHVAC.Turbo', 7);
        $this->RegisterVariableBoolean('TasmotaHVAC_Econo', 'Econo Mode', 'TasmotaHVAC.Econo', 8);
        $this->RegisterVariableFloat('TasmotaHVAC_Temperature', 'Temperature', '~Temperature.Room', 9);

        $this->EnableAction('TasmotaHVAC_Power');
        $this->EnableAction('TasmotaHVAC_Mode');
        $this->EnableAction('TasmotaHVAC_FanSpeed');
        $this->EnableAction('TasmotaHVAC_SwingV');
        $this->EnableAction('TasmotaHVAC_SwingH');
        $this->EnableAction('TasmotaHVAC_Quiet');
        $this->EnableAction('TasmotaHVAC_Turbo');
        $this->EnableAction('TasmotaHVAC_Econo');
        $this->EnableAction('TasmotaHVAC_Temperature');

        //Anzahl die in der Konfirgurationsform angezeigt wird - Hier Standard auf 1
        $this->RegisterPropertyString('Topic', '');
        $this->RegisterPropertyString('On', 'ON');
        $this->RegisterPropertyString('Off', 'OFF');
        $this->RegisterPropertyString('FullTopic', '%prefix%/%topic%');
        $this->RegisterPropertyInteger('PowerOnState', 3);
        $this->RegisterPropertyInteger('GatewayMode', 0);
        $this->RegisterPropertyBoolean('MessageRetain', false);
        $this->RegisterVariableFloat('Tasmota_RSSI', 'RSSI');
        $this->RegisterVariableBoolean('Tasmota_DeviceStatus', 'Status', 'Tasmota.DeviceStatus');
        //Settings
        $this->RegisterPropertyBoolean('SystemVariables', false);
        $this->RegisterPropertyString('AircoType', "MITSUBISHI_AC");

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

        switch ($Ident) {
        case 'TasmotaHVAC_Power':
            $this->setNewValue("TasmotaHVAC_Power", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_Mode':
            $this->setNewValue("TasmotaHVAC_Mode", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_FanSpeed':
            $this->setNewValue("TasmotaHVAC_FanSpeed", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_SwingV':
            $this->setNewValue("TasmotaHVAC_SwingV", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_SwingH':
            $this->setNewValue("TasmotaHVAC_SwingH", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_Quiet':
            $this->setNewValue("TasmotaHVAC_Quiet", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_Turbo':
            $this->setNewValue("TasmotaHVAC_Turbo", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_Econo':
            $this->setNewValue("TasmotaHVAC_Econo", $Value);
            $this->sendCommandToHVAC();
            break;
        case 'TasmotaHVAC_Temperature':
            $this->setNewValue("TasmotaHVAC_Temperature", $Value);
            $this->sendCommandToHVAC();
            break;

        default:
            // code...
            break;
        }

    }

    protected function sendCommandToHVAC() {
        global $modes, $fanSpeeds, $swingHs, $swingVs;
        $payload['Vendor'] = $this->ReadPropertyString('AircoType');
        $payload['Power'] = $this->GetValue("TasmotaHVAC_Power") ? "On" : "Off";
        $payload['Mode'] = $modes[$this->GetValue("TasmotaHVAC_Mode")][1];
        $payload['FanSpeed'] = $fanSpeeds[$this->GetValue("TasmotaHVAC_FanSpeed")][1];

        $payload['SwingV'] = $swingVs[$this->GetValue("TasmotaHVAC_SwingV")][1];
        $payload['SwingH'] = $swingHs[$this->GetValue("TasmotaHVAC_SwingH")][1];
        $payload['Quiet'] = $this->GetValue("TasmotaHVAC_Quiet") ? "On" : "Off";
        $payload['Turbo'] = $this->GetValue("TasmotaHVAC_Turbo") ? "On" : "Off";
        $payload['Econo'] = $this->GetValue("TasmotaHVAC_Econo") ? "On" : "Off";
        $payload['Temp'] = $this->getValue("TasmotaHVAC_Temperature");

        $payloadJSON = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->SendDebug(__FUNCTION__ . ' JSON', $payloadJSON, 0);

    }

    protected function setNewValue($name, $value) {
        $sid = @IPS_GetObjectIDByIdent($name, $this->InstanceID);
        // $this->SendDebug(__FUNCTION__, "SV: ".$name." -> ".$sid." -> ".$value,0);
        if ($sid) {
            SetValue($sid, $value);
        }

    }

    protected function GetValue($name, $value) {
        $sid = @IPS_GetObjectIDByIdent($name, $this->InstanceID);
        // $this->SendDebug(__FUNCTION__, "SV: ".$name." -> ".$sid." -> ".$value,0);
        if ($sid) {
            return GetValue($sid, $value);
        }
        return "";
    }

    private function createVariablenProfiles() {
        //Online / Offline Profile
        global $modes, $fanSpeeds, $swingHs, $swingVs;

        $this->RegisterProfileBooleanEx('TasmotaHVAC.DeviceStatus', 'Network', '', '', [
            [false, 'Offline', '', 0xFF0000],
            [true, 'Online', '', 0x00FF00],
        ]);

        $this->RegisterProfileBooleanEx("TasmotaHVAC.Power", "Power", "", "", [
            [false, 'Off', '', 0xFF0000],
            [true, 'On', '', 0x00FF00],
        ]);
        $this->RegisterProfileIntegerEx("TasmotaHVAC.Mode", "Mode", "", "", $modes);
        $this->RegisterProfileIntegerEx("TasmotaHVAC.FanSpeed", "FanSpeed", "", "", $fanSpeeds);
        $this->RegisterProfileIntegerEx("TasmotaHVAC.SwingV", "SwingV", "", "", $swingVs);
        $this->RegisterProfileIntegerEx("TasmotaHVAC.SwingH", "SwingH", "", "", $swingHs);
        $this->RegisterProfileBooleanEx("TasmotaHVAC.Quiet", "Quiet", "", "", [
            [false, 'Off', '', 0xFF0000],
            [true, 'On', '', 0x00FF00],
        ]);
        $this->RegisterProfileBooleanEx("TasmotaHVAC.Turbo", "Turbo", "", "", [
            [false, 'Off', '', 0xFF0000],
            [true, 'On', '', 0x00FF00],
        ]);
        $this->RegisterProfileBooleanEx("TasmotaHVAC.Econo", "Econo", "", "", [
            [false, 'Off', '', 0xFF0000],
            [true, 'On', '', 0x00FF00],
        ]);

    }
}
