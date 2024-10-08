<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class myhome extends eqLogic {
    /*     * *************************Attributs****************************** */
	
	
    /*     * ***********************Methode static*************************** */

	public static function cronDaily() {
		if (config::byKey('jeeNetwork::mode') == 'master') {
			if (config::byKey('auto_updateConf', 'myhome') == 1) {
				try {
					myhome::syncconfMyhome();
				} catch (Exception $e) {
				}
			}
		}
	}
	
	public static function dependancy_info() {
		$return = array();
        $return['log'] = log::getPathToLog(__CLASS__ . '_update');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
        if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependance')) {
            $return['state'] = 'in_progress';
		} else {
            if (exec(system::getCmdSudo() . system::get('cmd_check') . '-Ec "python3\-requests|python3\-voluptuous|python3\-bs4"') < 3) { // adaptez la liste des paquets et le total
			$return['state'] = 'nok';
            } elseif (exec(system::getCmdSudo() . 'pip3 list | grep -Ewc "aiohttp"') < 1) { // adaptez la liste des paquets et le total
                $return['state'] = 'nok';
            } else {
                $return['state'] = 'ok';
            }
		}
		return $return;
	}

	public static function dependancy_install() {
        log::remove(__CLASS__ . '_update');
        return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependance', 'log' => log::getPathToLog(__CLASS__ . '_update'));
	}

	public static function deamon_info() {
		$return = array();
		$return['log'] = 'myhomecmd';
		$return['state'] = 'nok';
		$pid_file = '/tmp/myhome.pid';
		if (file_exists($pid_file)) {
			if (posix_getsid(trim(file_get_contents($pid_file)))) {
				$return['state'] = 'ok';
			} else {
				shell_exec('sudo rm -rf ' . $pid_file . ' 2>&1 > /dev/null;rm -rf ' . $pid_file . ' 2>&1 > /dev/null;');
			}
		}
		$return['launchable'] = 'ok';
		$port = config::byKey('port', 'myhome');
		if ($port != 'auto') {
			$port = jeedom::getUsbMapping($port);
			if (@!file_exists($port)) {
				$return['launchable'] = 'nok';
				$return['launchable_message'] = __('Le port n\'est pas configuré', __FILE__);
			}
			exec('sudo chmod 777 ' . $port . ' > /dev/null 2>&1');
		}
		return $return;
	}

	public static function deamon_start($_debug = false) {
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}
		log::remove('myhomecmd');
		$port = config::byKey('port', 'myhome');
		if ($port != 'auto') {
			$port = jeedom::getUsbMapping($port);
		}

		$myhome_path = realpath(dirname(__FILE__) . '/../../ressources/myhomecmd');

		if (file_exists('/tmp/config_myhome.xml')) {
			unlink('/tmp/config_myhome.xml');
		}
		$enable_logging = (config::byKey('enableLogging', 'myhome', 0) == 1) ? 'yes' : 'no';
		if (file_exists(log::getPathToLog('myhomecmd') . '.message')) {
			unlink(log::getPathToLog('myhomecmd') . '.message');
		}
		if (!file_exists(log::getPathToLog('myhomecmd') . '.message')) {
			touch(log::getPathToLog('myhomecmd') . '.message');
		}
		$replace_config = array(
            '#device#' => $port,
            '#serial_rate#' => config::byKey('serial_rate', 'myhome', 19200),
            '#socketport#' => config::byKey('socketport', 'myhome', 55004),
            '#log_path#' => log::getPathToLog('myhomecmd'),
            '#enable_log#' => $enable_logging,
            '#pid_path#' => '/tmp/myhome.pid',
        );
        if (config::byKey('jeeNetwork::mode') == 'slave') {
            $replace_config['#sockethost#'] = network::getNetworkAccess('internal', 'ip', '127.0.0.1');
			$replace_config['#trigger_url#'] = config::byKey('jeeNetwork::master::ip') . '/plugins/myhome/core/php/jeemyhome.php';
			$replace_config['#apikey#'] = config::byKey('jeeNetwork::master::apikey');
        } 
		else {
            $replace_config['#sockethost#'] = '127.0.0.1';
			$replace_config['#trigger_url#'] = network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/myhome/core/php/jeemyhome.php';
			$replace_config['#apikey#'] = config::byKey('api');
        }

		$config = template_replace($replace_config, file_get_contents($myhome_path . '/config_tmpl.xml'));
		file_put_contents('/tmp/config_myhome.xml', $config);
		chmod('/tmp/config_myhome.xml', 0777);
		if (!file_exists('/tmp/config_myhome.xml')) {
			throw new Exception(__('Impossible de créer : ', __FILE__) . '/tmp/config_myhome.xml');
		}
		$cmd = '/usr/bin/python ' . $myhome_path . '/myhomecmd.py -l -o /tmp/config_myhome.xml';
		if (log::getLogLevel('myhome')=='100') {
			$cmd .= ' -D';
		}
		log::add('myhomecmd', 'info', 'Lancement démon myhomecmd : ' . $cmd);
		$result = exec($cmd . ' >> ' . log::getPathToLog('myhomecmd') . ' 2>&1 &');
		if (strpos(strtolower($result), 'error') !== false || strpos(strtolower($result), 'traceback') !== false) {
			log::add('myhomecmd', 'error', $result);
			return false;
		}

		$i = 0;
		while ($i < 30) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') {
				myhome::send_trame("*13*66*##");
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('myhomecmd', 'error', 'Impossible de lancer le démon myhome, vérifiez le log myhomecmd', 'unableStartDeamon');
			return false;
		}
		message::removeAll('myhome', 'unableStartDeamon');
		log::add('myhomecmd', 'info', 'Démon Myhome lancé');
		return true;
	}

	public static function deamon_stop() {
		$pid_file = '/tmp/myhome.pid';
		if (file_exists($pid_file)) {
			$pid = intval(trim(file_get_contents($pid_file)));
			system::kill($pid);
		}
		system::fuserk(config::byKey('socketport', 'myhome', 55004));
	}

	public static function syncconfMyhome($_background = true) {
		if (config::byKey('jeeNetwork::mode') == 'master') {
			foreach (jeeNetwork::byPlugin('myhome') as $jeeNetwork) {
				try {
					$jeeNetwork->sendRawRequest('syncconfMyhome', array('plugin' => 'myhome'));
				} catch (Exception $e) {

				}
			}
		}
		log::remove('myhome.syncconf');
		$cmd = 'sudo /bin/bash ' . dirname(__FILE__) . '/../../ressources/syncconf.sh';
		if ($_background) {
			$cmd .= ' >> ' . log::getPathToLog('myhome.syncconf') . ' 2>&1 &';
		}
		log::add('myhome.syncconf', 'info', $cmd);
		shell_exec($cmd);
		/*foreach (self::byType('myhome') as $eqLogic) {
			$eqLogic->loadCmdFromConf(true);
		}*/
	}
	
	public static function send_trame($trame) {
		log::add('myhome','debug',"Send trame");
		if (config::byKey('jeeNetwork::mode') == 'master') {
			foreach (jeeNetwork::byPlugin('myhome') as $jeeNetwork) {
				$socket = socket_create(AF_INET, SOCK_STREAM, 0);
				socket_connect($socket, $jeeNetwork->getRealIp(), config::byKey('socketport', 'myhome', 55004));
				socket_write($socket, trim($trame), strlen(trim($trame)));
				socket_close($socket);
			}
		}
		
		if (config::byKey('port', 'myhome', 'none') != 'none') {
			$socket = socket_create(AF_INET, SOCK_STREAM, 0);
			socket_connect($socket, '127.0.0.1', config::byKey('socketport', 'myhome', 55004));
			socket_write($socket, trim($trame), strlen(trim($trame)));
			socket_close($socket);
		}
		
	}

	public static function createFromDef($_def) { 
        if (config::byKey('autoDiscoverEqLogic', 'myhome') == 0) {
            return false;
        }
        $banId = explode(' ', config::byKey('banmyhomeId', 'myhome'));
        if (in_array($_def['id'], $banId)) {
            return false;
        }
		
		//faire requete pour connaitre le type
		
        if (!isset($_def['id']) || !isset($_def['type']) || !isset($_def['media']) || $_def['id']=="NULL" 
				|| $_def['type']=="NULL" || $_def['media']=="NULL") {
            log::add('myhome', 'error', 'Information manquante pour ajouter l\'équipement : ' . print_r($_def, true));
            return false;
        }
		
        $myhome = myhome::byLogicalId($_def['id'], 'myhome');
        if (!is_object($myhome)) {
            $eqLogic = new myhome();
            $eqLogic->setName($_def['id']);
        }
        $eqLogic->setLogicalId($_def['id']);
        $eqLogic->setEqType_name('myhome');
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
		$eqLogic->setConfiguration('media', $_def['media']);
		
      
		if ($_def['type'] == "light" || $_def['type'] == "automatism" || $_def['type'] == "security" || $_def['type'] == "heating" ) {
			$eqLogic->setCategory($_def['type'], 1);
		} 
		else {
			$eqLogic->setCategory('default', 1);
		}
        $eqLogic->save();
		//$ref=myhome::checkDevice($_def['id']);
        return $eqLogic;
    }

    public static function devicesParameters($_device = '') {
        $path = dirname(__FILE__) . '/../config/devices';
        if (isset($_device) && $_device != '') {
            $files = ls($path, $_device . '.json', false, array('files', 'quiet'));
            if (count($files) == 1) {
                try {
                    $content = file_get_contents($path . '/' . $files[0]);
                    if (is_json($content)) {
                        $deviceConfiguration = json_decode($content, true);
                        return $deviceConfiguration[$_device];
                    }
                    return array();
                } catch (Exception $e) {
                    return array();
                }
            }
        }
        $files = ls($path, '*.json', false, array('files', 'quiet'));
        $return = array();
        foreach ($files as $file) {
            try {
                $content = file_get_contents($path . '/' . $file);
                if (is_json($content)) {
                    $return += json_decode($content, true);
                }
            } catch (Exception $e) {

            }
        }

        if (isset($_device) && $_device != '') {
            if (isset($return[$_device])) {
                return $return[$_device];
            }
            return array();
        }
        return $return;
    }

	public static function decrypt_trame($trame) {
	
		/*
		 // FONCTION : DECRYPTAGE D'UNE TRAME AU FORMAT LISIBLE
		// PARAMS : $trame=string
		// RETURN : array(
				"trame" => string,
				"mode" => string,
				"media" => 'string',
				"format" => 'string',
				"type" => 'string',
				"value" => string,
				"dimension" => string,
				"param" => string,
				"id" => string,
				"unit" => string,
				"date" => timestamp
		//Exemple
				array(
				"trame" => *2*2*#653565653##,
				"media" => CPL,
				"mode" => multicast,
				"format" => BUS_COMMAND,
				"type" => 2, (automation) Who
				"value" => 2 , (Move Down) What
				"dimension" => string,
				"param" => string,
				"id" => string,
				"unit" => string,				
				"date" => timestamp
				*/
				
		$def = new myhome_def();
		
		$ret_trame = array(
					"trame" => $trame,
					"format" => 'UNKNOWN',
					"mode" => 'UNKNOWN',
					"media" => 'ZIGBEE',
					"type" => 'UNKNOWN',
					"value" => NULL,
					"dimension" => NULL,
					"param" => NULL,
					"id" => NULL,
					"unit" => NULL,
					"date" => date("Y-m-d H:i:s", time())
		);
		
		$find_trame = false;
		//on teste le format de la trame
		foreach ($def->OWN_TRAME as $command => $command_reg) {
			//si on trouve un format valide de trame
			if (preg_match($command_reg, $ret_trame['trame'], $decode_trame)) {
				//on teste le type de la trame
				if ($command == 'BUS_COMMAND' && $decode_trame[1] != '1000') {
					$who = $decode_trame[1];
					$what = $decode_trame[2];
					$where = $decode_trame[3];
					$dimension = NULL;
					$val = NULL;
					$find_trame = true;
				} 
				elseif ($command == 'STATUS_REQUEST') {
					$who = $decode_trame[1];
					$what = NULL;
					$where = $decode_trame[2];
					$dimension = NULL;
					$val = NULL;
					$find_trame = true;
				} 
				elseif ($command == 'DIMENSION_REQUEST') {
					$who = $decode_trame[1];
					$what = $decode_trame[2];
					$where = $decode_trame[2];
					$dimension = $decode_trame[3];
					$val = $decode_trame[4];
					$find_trame = true;
				} 
				elseif ($command == 'DIMENSION_SET') {
					$who = $decode_trame[1];
					$what = $decode_trame[2];
					$where = $decode_trame[4];
					$dimension = $decode_trame[2];
					$val = $decode_trame[3];
					$find_trame = true;
				} 
				elseif ($command == 'ACK' || $command == 'NACK') {
					$who = NULL;
					$what = NULL;
					$where = NULL;
					$dimension = NULL;
					$val = NULL;
					$find_trame = true;
				}
				//Impossible de trouver la command dans ce format de la trame
				if ($find_trame == false) {
					continue;
				}
				//On sauvegarde le format
				$ret_trame["format"] = $command;
				//On test le type de la trame
				foreach ($def->OWN_TRAME_DEFINITION as $key => $value) {
					if ($key == $who) {
						$ret_trame["type"] = $def->OWN_TRAME_DEFINITION[$key]['TYPE'];
						//On recherche s'il existe les value/dimension/param dans la trame
						if (!is_null($what)
								&& (isset($def->OWN_TRAME_DEFINITION[$key][$what]) || isset($def->OWN_TRAME_DEFINITION[$key][$what.'_']))) {
							// on a un parametre on favorise avec le param
							if ($val && isset($def->OWN_TRAME_DEFINITION[$key][$what.'_'])) {
								$ret_trame["value"] = $def->OWN_TRAME_DEFINITION[$key][$what.'_'];
								// on test sans param
							} 
							elseif (isset($def->OWN_TRAME_DEFINITION[$key][$what])) {
								$ret_trame["value"] = $def->OWN_TRAME_DEFINITION[$key][$what];
								// on test avec param en dernier recours
							} 
							elseif (isset($def->OWN_TRAME_DEFINITION[$key][$what.'_'])) {
								$ret_trame["value"] = $def->OWN_TRAME_DEFINITION[$key][$what.'_'];
							}
						}
						if (!is_null($dimension)
								&& (isset($def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension]) || isset($def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension.'_']))) {
							// on a un parametre on favorise avec le param
							if ($val && isset($def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension.'_'])) {
								$ret_trame["dimension"] = $def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension.'_'];
								// on test sans param
							} 
							elseif (isset($def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension])) {
								$ret_trame["dimension"] = $def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension];
								// on test avec param en dernier recours
							} 
							elseif (isset($def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension.'_'])) {
								$ret_trame["dimension"] = $def->OWN_TRAME_DEFINITION[$key]['DIMENSION'][$dimension.'_'];
							}
						}
						if ($val) {
							$ret_trame["param"] = $val;
						}
					}
				}
				//ON RECUPE L'ID
				preg_match($def->OWN_WHERE_DEFINITION, $where, $matches_where);
				if (strlen($matches_where[1]) > 1) {
					$ret_trame["mode"] = $def->OWN_COMMUNICATION_DEFINITION[""];
					$matches_id = $matches_where[1];
				} 
				elseif (strlen($matches_where[2]) > 1) {
					$ret_trame["mode"] = $def->OWN_COMMUNICATION_DEFINITION[$matches_where[1]];
					$matches_id = $matches_where[2];
				}
				if (isset($matches_id)) {
					$ret_trame["id"] = myhomeCmd::getIdMyhome($matches_id);
					$ret_trame["unit"] = myhomeCmd::getUnitMyhome($matches_id);
				} 
				else {
					$ret_trame["id"] = NULL;
					$ret_trame["unit"] = NULL;
				}
				break;
			}
		}
		return $ret_trame;
	}

	public static function updateStatus($decrypted_trame) {
		//CAS DES VOLETS
		if ($decrypted_trame['type'] == 'automatism') {
			log::add('myhome','debug',"Update Status Automatism");
			myhome::updateStatusShutter($decrypted_trame, true);
		//CAS DES LUMIERES
		} 
		elseif ($decrypted_trame['type'] == 'light') {
			log::add('myhome','debug',"Update Status Light");
			myhome::updateStatusLight($decrypted_trame, true);
		//CAS DES SCENARIOS
		} 
		elseif ($decrypted_trame['type'] == 'scene') {
			log::add('myhome','debug',"Update Status Scenario");
			myhome::updateStatusScenario($decrypted_trame);
		//CAS DE LA THERMOREGULATION
		}
		elseif ($decrypted_trame['type'] == 'heating') {
			log::add('myhome','debug',"Update Status Chauffage");
			//myhome::updateStatusConfort($decrypted_trame, true);
		//ON NE S'EST PAS DE QUOI IL S'AGIT, ON QUITTE
		}
		else {
			return;
		}
	}
	
	public static function updateStatusLight($decrypted_trame, $scenarios=false) {
		/*
		// FONCTION : MISE A JOUR DU STATUS DES LIGHTS
		// PARAMS : $decrypted_trame = array(
						"trame" => string,
						"format" => 'string',
						"type" => 'string',
						"value" => string,
						"dimension" => string,
						"param" => string,
						"id" => string,
						"unit" => string,)
				$scenarios => boolean (true si l'on doit recherche des scenarios associés)
		*/
		
		$def = new myhome_def();
		//Creation des variables utiles
		$myhome = myhome::byLogicalId($decrypted_trame["id"], 'myhome');
		$family = $myhome->getConfiguration('family');
		$unit = $decrypted_trame["unit"];
		//On recupere la date de l'action
		$date = strtotime($decrypted_trame["date"]);
		//recuperation du unit principale de sauvegarde des status
		$statusid = "status".$unit;
		$myhomecmd = $myhome->getCmd('info', $statusid);
		$statusidnum = "statusnum".$unit;
		$myhomecmdnum = $myhome->getCmd('info', $statusidnum);
		$status = NULL;
		$statusnum = NULL;
		log::add('myhome','debug',"statusid : ".$statusidnum." date : ".$date." family : ".$family);
		
		//Allumage
		if ($decrypted_trame["value"] == 'ON') {
			$status = 'ON';
			$statusnum = 1;
			if ($family == 'DIMMER') {
				log::add('myhome','debug',"family type = DIMMER, Check Light Status");
				$Lightstatus="*#1*".hexdec($decrypted_trame["id"]).$decrypted_trame["unit"]."#9##";
				sleep(4);
				myhome::send_trame($Lightstatus);
			}
		}
		//Extinction 
		else if ($decrypted_trame["value"] == 'OFF') {
			$status = 'OFF';
			$statusnum = 0;
			
		}
		
		else {
			$status = 'ON';
			$statusnum = $decrypted_trame["value"];
		}
		
		//on n'a pas trouve le nouveau status, erreur dans la trame ?
		if ($status == NULL) {
			return;
		}
		
		log::add('myhome','debug',"mise a jour du status : ".$statusnum."\n");
		$myhomecmd->event($status);
		$myhomecmdnum->event($statusnum);
		$myhomecmd->save();
		$myhomecmdnum->save();
	}

	public static function updateStatusShutter($decrypted_trame, $scenarios=false) {
		/*
		// FONCTION : MISE A JOUR DU STATUS DES VOLETS
		// PARAMS : $decrypted_trame = array(
				"trame" => string,
				"mode" => string,
				"media" => 'string',
				"format" => 'string',
				"type" => 'string',
				"value" => string,
				"dimension" => string,
				"param" => string,
				"id" => string,
				"unit" => string,
				"date" => timestamp
		$scenarios => boolean (true si l'on doit recherche des scenarios associés)
		*/
		
		//$def = new myhome_def();
		
		$myhome = myhome::byLogicalId($decrypted_trame["id"], 'myhome');
		$unit = $decrypted_trame["unit"];
		$deviceType = explode('::', $myhome->getConfiguration('device'));
		$subDeviceType = $deviceType[1];
		
		//Récupération de l'horodatage de la commande
		$timestampCmd = strtotime($decrypted_trame["date"]);
		
		//Récupération des derniers statuts
		$statusId = 'status'.$unit;
		$statusCmd = $myhome->getCmd('info', $statusId);
		$maxDuration = $statusCmd->getConfiguration('DureeCmd');
		$lastStatus = $statusCmd->execCmd(null,2);
		$positionId = 'statusnum'.$unit;
		$positionCmd = $myhome->getCmd('info', $positionId);
		$lastPosition = $positionCmd->execCmd(null,0);
      	$cmdStatusId = 'statuscde'.$unit;
		$lastStatusUpdate = strtotime($statusCmd->getCollectDate());
      	$cmdStatusCmd = $myhome->getCmd('info', $cmdStatusId);
      	$cmdStatus = $cmdStatusCmd->execCmd();
      	$stopStatusId = 'statusstop'.$unit;
      	$stopStatusCmd = $myhome->getCmd('info', $stopStatusId);
      	$stopStatus = $stopStatusCmd->execCmd();
      	$closedStatusId = 'statusclosed'.$unit;
      	$closedStatusCmd = $myhome->getCmd('info', $closedStatusId);
      	$closedStatus = $closedStatusCmd->execCmd();

		//Contrôle si update des statuts nécessaire
		if ($decrypted_trame["value"] == 'MOVE_UP'
			|| $decrypted_trame["value"] == 'MOVE_DOWN'
			|| $decrypted_trame["value"] == 'MOVE_STOP') {
			$shutterCmd = $decrypted_trame["value"];
		} 
		else {
			return;
		}

      //Gestion du template volet inversé
      	if ($subDeviceType == '01') {
          	switch ($shutterCmd) {
				case 'MOVE_UP' :
		            $shutterCmd = 'MOVE_DOWN';
				    log::add('myhome','debug','---------------------------------------------------------------------------------------');
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [subDeviceType] => '.$subDeviceType.' [date] =>'.$decrypted_trame["date"].' [cmd] => '.$decrypted_trame["value"].' [updatedCmd] => '.$shutterCmd);
				    log::add('myhome','debug','---------------------------------------------------------------------------------------');
                	break;
				case 'MOVE_DOWN' :
                	$shutterCmd = 'MOVE_UP';
				    log::add('myhome','debug','---------------------------------------------------------------------------------------');
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [subDeviceType] => '.$subDeviceType.' [date] =>'.$decrypted_trame["date"].' [cmd] => '.$decrypted_trame["value"].' [updatedCmd] => '.$shutterCmd);
				    log::add('myhome','debug','---------------------------------------------------------------------------------------');
                	break;
            }    
        }
      
	    log::add('myhome','debug','---------------------------------------------------------------------------------------');
		log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [subDeviceType] => '.$subDeviceType.' [date] =>'.$decrypted_trame["date"].' [cmd] => '.$decrypted_trame["value"].' [timestampCmd] => '.$timestampCmd);
		log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [lastStatusUpdate] => '.$statusCmd->getCollectDate().' [lastStatusUpdate] => '.$lastStatusUpdate.' [lastStatus] => '.$lastStatus.' [lastPosition] => '.$lastPosition.'%'.' [maxDuration] => '.$maxDuration.'s');
		log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmdStatus] => '.$cmdStatus.' [stopStatus] => '.$stopStatus.' [closedStatus] => '.$closedStatus);


		//Gestion de la durée maxi du mouvement
		if (is_numeric($maxDuration) && $maxDuration > 0) {
			$maxMoveTime = $maxDuration;
		} 
		else {
			$maxMoveTime = 30;
		}
		
		//Mise à jour de la position et du statut du volet
		$movementTime = 0;
		$deplacement = 0;
        $closedStatus = 0;
		if ($cmdStatus != 0 && $cmdStatus != 1) {
       		$cmdStatus = 0;
        }
		if ($stopStatus != 0 && $stopStatus != 1) {
       		$stopStatus = 0;
        }
		switch ($shutterCmd) {
			case 'MOVE_UP' :
				if ($lastStatus == 'UP' || $lastStatus == 'OPEN') {
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmd] => '.$decrypted_trame["value"].' [lastStatus] => '.$lastStatus.' Mise à jour du statut non nécéssaire!');
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmdStatus] => '.$cmdStatus.' [stopStatus] => '.$stopStatus.' [closedStatus] => '.$closedStatus);
					log::add('myhome','debug','---------------------------------------------------------------------------------------');
					return;
				}
				if ($lastStatus == 'DOWN') {
					$movementTime = $timestampCmd - $lastStatusUpdate;
					$deplacement = round(-$movementTime * 100 / $maxMoveTime);
				}
				$status = 'UP';
            	$cmdStatus = 1;
 		      	$cmdStatusCmd->event($cmdStatus);
 				break;		
			case 'MOVE_DOWN' :
				if ($lastStatus == 'DOWN' || $lastStatus == 'CLOSED') {
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmd] => '.$decrypted_trame["value"].' [lastStatus] => '.$lastStatus.' Mise à jour du statut non nécéssaire!');
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmdStatus] => '.$cmdStatus.' [stopStatus] => '.$stopStatus.' [closedStatus] => '.$closedStatus);
					log::add('myhome','debug','---------------------------------------------------------------------------------------');
					return;
				}
				if ($lastStatus == 'UP') {
					$movementTime = $timestampCmd - $lastStatusUpdate;
					$deplacement = round($movementTime * 100 / $maxMoveTime);
				}
				$status = 'DOWN';
            	$cmdStatus = 0;
		      	$cmdStatusCmd->event($cmdStatus);
				break;
			case 'MOVE_STOP' :
				if ($lastStatus != 'UP' && $lastStatus != 'DOWN') {
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmd] => '.$decrypted_trame["value"].' [lastStatus] => '.$lastStatus.' Mise à jour du statut non nécéssaire!');
					log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmdStatus] => '.$cmdStatus.' [stopStatus] => '.$stopStatus.' [closedStatus] => '.$closedStatus);
					log::add('myhome','debug','---------------------------------------------------------------------------------------');
					return;
				}
				$movementTime = $timestampCmd - $lastStatusUpdate;
				$deplacement = round($movementTime * 100 / $maxMoveTime);
				if ($lastStatus == 'DOWN') {
					$deplacement = -$deplacement;
				}
             	$stopStatus = !$stopStatus;
 		      	$stopStatusCmd->event($stopStatus);
				break;
			defaut :
				return;
		}
		
		log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [movementTime] => '.$movementTime.'s'.' [deplacement] => '.$deplacement.'%'.' [lastPosition] => '.$lastPosition.'%');

		$position = $lastPosition + $deplacement;
		if ($status != 'UP' && $status != 'DOWN') {
      		$status = $position;
			if ($position >= 100) {
				$position = 100;
				$status = 'OPEN';
			}
			if ($position <= 0) {
				$position = 0;
				$status = 'CLOSED';
            	$closedStatus = 1;
			}
        }
		log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmd] => '.$decrypted_trame["value"].' [lastStatus] => '.$lastStatus.' [newStatus] => '.$status.' [newPosition] => '.$position.'%'.' Mise à jour du statut!');
		log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmdStatus] => '.$cmdStatus.' [stopStatus] => '.$stopStatus.' [closedStatus] => '.$closedStatus);
		log::add('myhome','debug','---------------------------------------------------------------------------------------');
		
		$statusCmd->event($status,$decrypted_trame["date"]);
		$positionCmd->event($position,$decrypted_trame["date"]);
      	$closedStatusCmd->event($closedStatus,$decrypted_trame["date"]);

        if ($status != 'UP' && $status != 'DOWN') {
           return;
        }
        if ($status == 'UP') {
            $remainingMoveTime = round((100 - $position) * $maxMoveTime / 100);
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
            log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmd] => '.$decrypted_trame["value"].' [position] => '.$position.'%'.' [remainingMoveTime] =>'.$remainingMoveTime.'s');
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
        }		
        if ($status == 'DOWN') {
            $remainingMoveTime = round($position * $maxMoveTime / 100);
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
            log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [cmd] => '.$decrypted_trame["value"].' [position] => '.$position.'%'.' [remainingMoveTime] =>'.$remainingMoveTime.'s');
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
        }		
 		sleep ($remainingMoveTime + 2);
		$lastStatus = $statusCmd->execCmd(null,2);
		$lastStatusUpdate = $statusCmd->getCollectDate();
		if (strtotime($lastStatusUpdate) == strtotime($decrypted_trame["date"]) && $lastStatus ==  $status) {
			$stopCmd="*2*0*".hexdec($decrypted_trame["id"]).$decrypted_trame["unit"]."#9##";
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
        	log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [date] =>'.$decrypted_trame["date"].' [cmd] => '.$decrypted_trame["value"].' [lastStatusUpdate] => '.$lastStatusUpdate.' [lastStatus] => '.$lastStatus.' [status] => '.$status.' Envoi commande STOP!');
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
			myhome::send_trame($stopCmd);
        } 
      	else{
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
        	log::add('myhome','debug','[cmdId] => '.$statusCmd->getId().' [shutterId] =>'.hexdec($decrypted_trame["id"]).' [date] =>'.$decrypted_trame["date"].' [cmd] => '.$decrypted_trame["value"].' [lastStatusUpdate] => '.$lastStatusUpdate.' [lastStatus] => '.$lastStatus.' [status] => '.$status.' Le statut du volet à changer!');
            log::add('myhome','debug','---------------------------------------------------------------------------------------');
       }
      
	}
  
/*     * *********************Methode d'instance************************* */

	public function preSave() {
		$Id = sprintf("%08s",strtoupper($this->getLogicalId()));
		$this->setLogicalId($Id);

	}

	public function postSave() {
		if ($this->getConfiguration('applyDevice') != $this->getConfiguration('device')) {
			$this->applyModuleConfiguration();
		}
		
	}

	public function applyModuleConfiguration() {
		$this->setConfiguration('applyDevice', $this->getConfiguration('device'));
		$this->save();
		if ($this->getConfiguration('device') == '') {
			return true;
		}
		$device_type = explode('::', $this->getConfiguration('device'));
		$deviceref = $device_type[0];
		$subtype = $device_type[1];
		$device = self::devicesParameters($deviceref);
		if (!is_array($device)) {
			return true;
		}
		if (isset($device['configuration'])) {
			foreach ($device['configuration'] as $key => $value) {
				$this->setConfiguration($key, $value);
				$this->save();
			}
		}
		if (!isset($device['subtype'][$subtype])) {
			if (count($device['subtype']) != 1) {
				return true;
			}
			$device = reset($device['subtype']);
		} 
		else {
			$device = $device['subtype'][$subtype];
		}
		if (isset($device['category'])) {
			foreach ($device['category'] as $key => $value) {
				$this->setCategory($key, $value);
			}
		}
		$cmd_order = 0;
		$link_cmds = array();
		$link_actions = array();
		foreach ($device['commands'] as $command) {
			$cmd = null;
			foreach ($this->getCmd() as $liste_cmd) {
				if ($liste_cmd->getLogicalId() == $command['logicalId']) {
					if ($liste_cmd->getConfiguration('unit') == $command['configuration']['unit']) {
						$cmd = $liste_cmd;
						break;
					}
				}
			}
			try {
				if ($cmd == null || !is_object($cmd)) {
					$cmd = new myhomeCmd();
					$cmd->setOrder($cmd_order);
					$cmd->setEqLogic_id($this->getId());
				} 
				else {
					$command['name'] = $cmd->getName();
				}
				utils::a2o($cmd, $command);
				$cmd->save();
				if (isset($command['value'])) {
					$link_cmds[$cmd->getId()] = $command['value'];
				}
				if (isset($command['configuration']) && isset($command['configuration']['updateCmdId'])) {
					$link_actions[$cmd->getId()] = $command['configuration']['updateCmdId'];
				}
				$cmd_order++;
			} catch (Exception $exc) {

			}
		}

		if (count($link_cmds) > 0) {
			foreach ($this->getCmd() as $eqLogic_cmd) {
				foreach ($link_cmds as $cmd_id => $link_cmd) {
					if ($link_cmd == $eqLogic_cmd->getName()) {
						$cmd = cmd::byId($cmd_id);
						if (is_object($cmd)) {
							$cmd->setValue($eqLogic_cmd->getId());
							$cmd->save();
						}
					}
				}
			}
		}
		if (count($link_actions) > 0) {
			foreach ($this->getCmd() as $eqLogic_cmd) {
				foreach ($link_actions as $cmd_id => $link_action) {
					if ($link_action == $eqLogic_cmd->getName()) {
						$cmd = cmd::byId($cmd_id);
						if (is_object($cmd)) {
							$cmd->setConfiguration('updateCmdId', $eqLogic_cmd->getId());
							$cmd->save();
						}
					}
				}
			}
		}


		$this->save();
	}

/*     * **********************Getteur Setteur*************************** */
} 	

class myhomeCmd extends cmd {
    /*     * *************************Attributs****************************** */


    /*     * ***********************Methode static*************************** */
	
	public static function calc_myhome_to_light($myhome_value) {
		/*
		// FONCTION : CALCUL UNE VALEUR MYHOME DE LUMIERE EN POURCENTAGE
		// PARAMS : $myhome_value => string
		// RETOURNE : LA VALEUR EN POURCENTAGE
		*/
		
		// Augmentation
		if ($myhome_value < 128) {
			$percent = $myhome_value;
			// Diminution
		} 
		else {
			$percent = $myhome_value - 256;
		}
		return $percent;
	}

	public static function calc_myhome_to_time($myhome_value) {
		/*
		// FONCTION : CALCUL UNE VALEUR MYHOME D'UNE TEMPORISATION
		// PARAMS : $myhome_value => string
		// RETOURNE : LA VALEUR EN SECONDES
		*/
		$time = $myhome_value / 5;
		//On arrondi à la seconde supérieure
		$time = round($time, 0, PHP_ROUND_HALF_UP);
		return $time;
	}

	public static function calc_myhome_to_temp($myhome_value1, $myhome_value2) {
		/*
		// FONCTION : CALCUL UNE VALEUR MYHOME DECOMPOSE DE TEMPERATTURE EN UNE VALEUR ENTIERE
		// PARAMS : $myhome_value1 => string, $myhome_value2 => string
		// RETOURNE : LA VALEUR EN POURCENTAGE
		*/
	
		//TODO : Corriger pour les valeur negative 
		$value = ($myhome_value1*256)+$myhome_value2;
		return $value;
	}
	
	public static function myhomeId_to_ownId($id, $unit) {
		/*
		// FONCTION : TRANSFORME UN ID ET UN UNIT MYHOME EN UN ID OPENWEBNET
		// PARAMS : $id=string|int,$unit=string|int
		// RETURN : $ownId=int
		*/
		$unit = sprintf("%02d", $unit);
		$iddec = hexdec($id);
		$ownId = $iddec.$unit;
		return ($ownId);
	}
	
	public static function getIdMyhome($own_id) {
		/*
		// FONCTION : RECUPERATION DE L'ID MYHOME DANS UN ID OPENWEBNET
		// PARAMS : $own_id=string|int
		// RETURN : $Id=int
		*/
		$Iddec = substr($own_id, 0, -2);
		$Id = sprintf("%08s",strtoupper(dechex($Iddec)));
		return ($Id);
	}

	public static function getUnitMyhome($own_id) {
		/*
		// FONCTION : RECUPERATION DU UNIT DE L'ID MYHOME DANS UN ID OPENWEBNET
		// PARAMS : $own_id=string|int
		// RETURN : $Unit=int
		*/
		$Unit = substr($own_id, -2);
		return ($Unit);
	}

    /*     * *********************Methode d'instance************************* */

	public function execute($_options = null) {
		
		if ($this->getType() == 'action') {
			$whatdim = $this->getConfiguration('whatdim');
			$what = $whatdim["what"];
			$dim = $whatdim["dim"];
			
			$unit= $this->getConfiguration('unit');
			
			$logicalId = myhomeCmd::myhomeId_to_ownId($this->getEqlogic()->getLogicalId(), $unit);

			$value = trim(str_replace("#WHAT#", $what, $this->getLogicalId()));
			$value = trim(str_replace("#what#", $what, $value));
			$value = trim(str_replace("#DIM#", $dim, $value));
			$value = trim(str_replace("#dim#", $dim, $value));
			
						
			$where = $this->getConfiguration('where');
			
			if ($where == "Unicast") {
				$whereid = "";
			} 
			elseif ($where == "Broadcast") {
				$whereid = "0#";
			} 
			elseif ($where == "Multicast") {	
				$whereid = "#";
			} 
			$value = trim(str_replace("#MEDIA#", "#9", $value));
			$value = trim(str_replace("#media#", "#9", $value));
			
			$value = trim(str_replace("#WHERE#", $whereid, $value));
			$value = trim(str_replace("#where#", $whereid, $value));
			
			$value = trim(str_replace("#IDUNIT#", $logicalId, $value));
			$value = trim(str_replace("#idunit#", $logicalId, $value));
			foreach ($this->getEqlogic()->getCategory() as $key => $getcat) {
				if ($getcat==1) {
					$category=$key;
				}
            }
			
			if ($category == "heating") {
				$who = "4";
			} 
			elseif ($category == "security") {
				$who = "5";
			} 
			elseif ($category == "energy") {	
				$who = "18";
			} 
			elseif ($category == "light") {	
				$who = "1";
			} 
			elseif ($category == "automatism") {	
				$who = "2";
			} 
						
			$value = trim(str_replace("#WHO#", $who, $value));

			
            switch ($this->getSubType()) {
                case 'slider':
                $value = str_replace('#slider#', strtoupper(intval($_options['slider'])), $value);
				$value = str_replace('#SLIDER#', strtoupper(intval($_options['slider'])), $value);
                break;
                case 'color':
                $value = str_replace('#color#', $_options['color'], $value);
				$value = str_replace('#COLOR#', $_options['color'], $value);
                break;
				}
				
				$values = explode('&&', $value);
			
            if (config::byKey('jeeNetwork::mode') == 'master') {
                foreach (jeeNetwork::byPlugin('myhome') as $jeeNetwork) {
                    foreach ($values as $value) {
                        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
                        socket_connect($socket, $jeeNetwork->getRealIp(), config::byKey('socketport', 'myhome', 55004));
                        socket_write($socket, trim($value), strlen(trim($value)));
                        socket_close($socket);
						log::add ('myhome','event','Send from Jeedom : '.$value);
                    }
                }
            }
            if (config::byKey('port', 'myhome', 'none') != 'none') {
                foreach ($values as $value) {
                    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
                    socket_connect($socket, '127.0.0.1', config::byKey('socketport', 'myhome', 55004));
                    socket_write($socket, trim($value), strlen(trim($value)));
                    socket_close($socket);
					log::add ('myhome','event','Send from Jeedom : '.$value);
                }
            }
        }
    }

    /*     * **********************Getteur Setteur*************************** */

}

class myhome_def {

	//Definition des differentes trames MyHome possibles
	public $OWN_TRAME = array(
			'ACK' => "/^\*#\*1##$/",//  *#*1##
			'NACK' => "/^\*#\*0##$/",//  *#*0##
			'BUS_COMMAND' => "/^\*(\d+)\*(\d*#*\d*)\*(\d*#*\d*)\**##$/",//  *WHO*WHAT*WHERE##
			'STATUS_REQUEST' => "/^\*#(\d+)\*(\d+#*\d*)##$/",//  *#WHO*WHERE
			'STATUS_RESPONSE' => "/^\*#(\d+)\*(\d+)\*(\d+#*\d*)##$/",//  *#WHO*WHAT*WHERE##
			'DIMENSION_REQUEST' => "/^\*#(\d+)\*(\d*#*\d*)\*(\d+)##$/",//  *#WHO*WHERE*DIMENSION##
			'DIMENSION_SET' => "/^\*#(\d+)\*(\d*#*\d*)\*#(\d*)\*([\d*\**]+)##$/",//  *#WHO*WHERE*#DIMENSION*VAL1*VALn##
			'DIMENSION_REQUEST_RESPONSE' => "/^\*#(\d+)\*(\d*#*\d*)\*(\d+)\*([\d*\**\d*]+)##$/"//*#WHO*WHERE*DIMENSION*VAL1*VALn##

	);

	//Definition des differents type de contenu d'une trame
	public $OWN_TRAME_DEFINITION = array(
			"0" => array(
				"TYPE" => "scene",
				"1" => "EXECUTE_SCENARIO_1",
				"2" => "EXECUTE_SCENARIO_2",
				"3" => "EXECUTE_SCENARIO_3",
				"4" => "EXECUTE_SCENARIO_4",
				"5" => "EXECUTE_SCENARIO_5",
				"6" => "EXECUTE_SCENARIO_6",
				"7" => "EXECUTE_SCENARIO_7",
				"8" => "EXECUTE_SCENARIO_8",
				"9" => "EXECUTE_SCENARIO_9",
				"10" => "EXECUTE_SCENARIO_10",
				"11" => "EXECUTE_SCENARIO_11",
				"12" => "EXECUTE_SCENARIO_12",
				"13" => "EXECUTE_SCENARIO_13",
				"14" => "EXECUTE_SCENARIO_14",
				"15" => "EXECUTE_SCENARIO_15",
				"16" => "EXECUTE_SCENARIO_16",
				"17" => "EXECUTE_SCENARIO_17",
				"18" => "EXECUTE_SCENARIO_18",
				"19" => "EXECUTE_SCENARIO_19",
				"20" => "EXECUTE_SCENARIO_20",
				"21" => "EXECUTE_SCENARIO_21",
				"22" => "EXECUTE_SCENARIO_22",
				"23" => "EXECUTE_SCENARIO_23",
				"24" => "EXECUTE_SCENARIO_24",
				"25" => "EXECUTE_SCENARIO_25",
				"26" => "EXECUTE_SCENARIO_26",
				"27" => "EXECUTE_SCENARIO_27",
				"28" => "EXECUTE_SCENARIO_28",
				"29" => "EXECUTE_SCENARIO_29",
				"30" => "EXECUTE_SCENARIO_30",
				"31" => "EXECUTE_SCENARIO_31",
				"32" => "EXECUTE_SCENARIO_32",
				"40#" => "START_RECORDING_SCENARIO",
				"41#" => "END_RECORDING_SCENARIO",
				"42" => "ERASE_ALL_SCENARIO",
				"42#" => "ERASE_SCENARIO",
				"45" => "UNAVAILABLE_SCENARIOS_CENTRAL_UNIT",
				"46" => "MEMORY_FULL_OF_SCENARIOS_CENTRAL_UNIT"
			), //0 Scenarios
			"1" => array(
				"TYPE" => "light",
				"1" => "ON",
				"0" => "OFF",
				"0#" => "OFF_AT_X_SPEED",
				"1#" => "OFF_AT_X_SPEED",
				"2" => "2",
				"3" => "3",
				"4" => "4",
				"5" => "5",
				"6" => "6",
				"7" => "7",
				"8" => "8",
				"9" => "9",
				"10" => "10",
				"DIMENSION" => array(
					"_" => "LIGHT_STATUS_REQUEST",
					"1_" => "GET_SET_DIMMMING_AND_SPEED"
				)
			), //1 light
			"2" => array(
				"TYPE" => "automatism",
				"12" => "DOWN_ADVANCED",
				"11" => "UP_ADVANCED",
				"10" => "STOP_ADVANCED",
				"2" => "MOVE_DOWN",
				"1" => "MOVE_UP",
				"0" => "MOVE_STOP"
			), //2 shutter
			"13" => array(
				"TYPE" => "MANAGEMENT",
				"22" => "RESET_DEVICE",
				"30" => "CREATE_NETWORK",
				"31" => "CLOSE_NETWORK",
				"32" => "OPEN_NETWORK",
				"33" => "JOIN_NETWORK",
				"34" => "LEAVE_NETWORK",
				"60" => "KEEP_CONNECT",
				"65" => "SCAN",
				"66" => "SUPERVISOR",
				"DIMENSION" => array(
					"12" => "MAC_ADDRESS",
					"16" => "FIRMWARE_VERSION",
					"17" => "HARDWARE_VERSION",
					"26" => "WHO_IMPLEMENTED",
					"66_" => "PRODUCT_INFORMATION",
					"67" => "GET_NUMBER_OF_NETWORK_PRODUCT",
					"70" => "IDENTITY",
					"71" => "ZIGBEE_CHANNEL"
				)
			), //13 Management
	);
	
	//Definition de la partie WHERE d'une trame
	public $OWN_WHERE_DEFINITION = "/(\d+)?#*(\d+)?#*(\d*)$/";

	//Definition des differents media
	public $OWN_COMMUNICATION_DEFINITION = array(
			"" => "UNICAST",
			"0" => "BROADCAST",
			"1" => "MULTICAST"
	);
}


?>
