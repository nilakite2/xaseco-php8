<?php

declare(strict_types=1);

/**
 * Include required classes
 */
require_once 'includes/types.inc.php';
require_once 'includes/basic.inc.php';
require_once 'includes/GbxRemote.inc.php';
require_once 'includes/xmlparser.inc.php';
require_once 'includes/gbxdatafetcher.inc.php';
require_once 'includes/tmndatafetcher.inc.php';
require_once 'includes/rasp.settings.php';

/**
 * Runtime configuration definitions
 */

const ABBREV_COMMANDS = false;
const INHIBIT_RECCMDS = false;
const MONTHLY_LOGSDIR = false;
const CONFIG_UTF8ENCODE = false;

/**
 * System definitions - no changes below this point
 */

const XASECO_VERSION = '1.17';
const XASECO_TMN = 'http://www.gamers.org/tmn/';
const XASECO_TMF = 'http://www.gamers.org/tmf/';
const XASECO_TM2 = 'http://www.gamers.org/tm2/';
const XASECO_ORG = 'http://www.xaseco.org/';

const TMN_BUILD = '2006-05-30';
const TMF_BUILD = '2011-02-21';

define('CRLF', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? "\r\n" : "\n");
//const LF = "\n";

/**
 * Error function
 * Report errors in a regular way.
 */
set_error_handler('displayError');
function displayError(int $errno, string $errstr, string $errfile, int $errline): void {
    global $aseco;

    if (error_reporting() === 0) {
        return;
    }

    switch ($errno) {
        case E_USER_ERROR:
            $message = "[XASECO Fatal Error] $errstr on line $errline in file $errfile" . CRLF;
            echo $message;
            doLog($message);

            $aseco?->releaseEvent('onShutdown', null);
            $aseco?->client?->query('SendHideManialinkPage');

            if (function_exists('xdebug_get_function_stack')) {
                doLog(print_r(xdebug_get_function_stack(), true));
            }
            exit(1);
        case E_USER_WARNING:
            $message = "[XASECO Warning] $errstr" . CRLF;
            echo $message;
            doLog($message);
            break;
        case E_ERROR:
            $message = "[PHP Error] $errstr on line $errline in file $errfile" . CRLF;
            echo $message;
            doLog($message);
            break;
        case E_WARNING:
            $message = "[PHP Warning] $errstr on line $errline in file $errfile" . CRLF;
            echo $message;
            doLog($message);
            break;
        default:
            if (strpos($errstr, 'Function call_user_method') !== false) {
                break;
            }
    }
}

/**
 * Here XASECO actually starts.
 */
class Aseco {

    public IXR_ClientMulticall_Gbx $client;
    public Examsly $xml_parser;
    public bool $debug;
    public Server $server;
    public array $settings = [];
    public array $chat_commands = [];
    public array $chat_colors = [];
    public array $chat_messages = [];
    public array $plugins = [];
    public array $titles = [];
    public array $masteradmin_list = [];
    public array $admin_list = [];
    public array $adm_abilities = [];
    public array $operator_list = [];
    public array $op_abilities = [];
    public array $bannedips = [];
    public bool $startup_phase;
    public bool $warmup_phase;
    public int $restarting;
    public bool $changingmode;
    public int $currstatus;
    public int $prevstatus;
    public int $currsecond;
    public int $prevsecond;
    public int $uptime;
	public array $style;
    public array $panels = [];
    public ?string $statspanel = null;

    /**
     * Initializes the server.
     */
    public function __construct(bool $debug) {
        global $maxrecs;

        echo '# initialize XASECO ###########################################################' . CRLF;

        $this->console_text('[XAseco] PHP Version is ' . phpversion() . ' on ' . PHP_OS);

        $this->uptime = time();
        $this->debug = $debug;
        $this->client = new IXR_ClientMulticall_Gbx();
        $this->xml_parser = new Examsly();
        $this->server = new Server('127.0.0.1', 5006, 'SuperAdmin', 'SuperAdmin');
        $this->server->challenge = new Challenge();
        $this->server->players = new PlayerList();
        $this->server->records = new RecordList($maxrecs);
        $this->server->mutelist = [];
        $this->plugins = [];
        $this->titles = [];
        $this->masteradmin_list = [];
        $this->admin_list = [];
        $this->adm_abilities = [];
        $this->operator_list = [];
        $this->op_abilities = [];
        $this->bannedips = [];
        $this->startup_phase = true;
        $this->warmup_phase = false;
        $this->restarting = 0;
        $this->changingmode = false;
        $this->currstatus = 0;
        $this->currsecond = time(); // Initializing the current second
        $this->prevsecond = $this->currsecond; // Initializing the previous second
    }

    /**
     * Load settings and apply them on the current instance.
     */
    public function loadSettings(string $config_file): void {

        if ($settings = $this->xml_parser->parseXml($config_file, true, CONFIG_UTF8ENCODE)) {
            $aseco = $settings['SETTINGS']['ASECO'][0];

            $this->chat_colors = $aseco['COLORS'][0];
            $this->chat_messages = $aseco['MESSAGES'][0];
            $this->masteradmin_list = $aseco['MASTERADMINS'][0];
            if (!isset($this->masteradmin_list) || !is_array($this->masteradmin_list)) {
                trigger_error('No MasterAdmin(s) configured in config.xml!', E_USER_ERROR);
            }

            if (empty($this->masteradmin_list['IPADDRESS'])) {
                if (($cnt = count($this->masteradmin_list['TMLOGIN'])) > 0) {
                    $this->masteradmin_list['IPADDRESS'] = array_fill(0, $cnt, '');
                }
            } else {
                if (count($this->masteradmin_list['TMLOGIN']) !== count($this->masteradmin_list['IPADDRESS'])) {
                    trigger_error("MasterAdmin mismatch between <tmlogin>'s and <ipaddress>'s!", E_USER_WARNING);
                }
            }

            $this->settings['lock_password'] = $aseco['LOCK_PASSWORD'][0];
            $this->settings['cheater_action'] = $aseco['CHEATER_ACTION'][0];
            $this->settings['script_timeout'] = $aseco['SCRIPT_TIMEOUT'][0];
            $this->settings['show_min_recs'] = $aseco['SHOW_MIN_RECS'][0];
            $this->settings['show_recs_before'] = $aseco['SHOW_RECS_BEFORE'][0];
            $this->settings['show_recs_after'] = $aseco['SHOW_RECS_AFTER'][0];
            $this->settings['show_tmxrec'] = $aseco['SHOW_TMXREC'][0];
            $this->settings['show_playtime'] = $aseco['SHOW_PLAYTIME'][0];
            $this->settings['show_curtrack'] = $aseco['SHOW_CURTRACK'][0];
            $this->settings['default_tracklist'] = $aseco['DEFAULT_TRACKLIST'][0];
            $this->settings['topclans_minplayers'] = $aseco['TOPCLANS_MINPLAYERS'][0];
            $this->settings['global_win_multiple'] = ($aseco['GLOBAL_WIN_MULTIPLE'][0] > 0 ? $aseco['GLOBAL_WIN_MULTIPLE'][0] : 1);
            $this->settings['window_timeout'] = $aseco['WINDOW_TIMEOUT'][0];
            $this->settings['adminops_file'] = $aseco['ADMINOPS_FILE'][0];
            $this->settings['bannedips_file'] = $aseco['BANNEDIPS_FILE'][0];
            $this->settings['blacklist_file'] = $aseco['BLACKLIST_FILE'][0];
            $this->settings['guestlist_file'] = $aseco['GUESTLIST_FILE'][0];
            $this->settings['trackhist_file'] = $aseco['TRACKHIST_FILE'][0];
            $this->settings['admin_client'] = $aseco['ADMIN_CLIENT_VERSION'][0];
            $this->settings['player_client'] = $aseco['PLAYER_CLIENT_VERSION'][0];
            $this->settings['default_rpoints'] = $aseco['DEFAULT_RPOINTS'][0];
            $this->settings['window_style'] = $aseco['WINDOW_STYLE'][0];
            $this->settings['admin_panel'] = $aseco['ADMIN_PANEL'][0];
            $this->settings['donate_panel'] = $aseco['DONATE_PANEL'][0];
            $this->settings['records_panel'] = $aseco['RECORDS_PANEL'][0];
            $this->settings['vote_panel'] = $aseco['VOTE_PANEL'][0];

            $this->settings['welcome_msg_window'] = strtoupper($aseco['WELCOME_MSG_WINDOW'][0]) === 'TRUE';
            $this->settings['log_all_chat'] = strtoupper($aseco['LOG_ALL_CHAT'][0]) === 'TRUE';
            $this->settings['chatpmlog_times'] = strtoupper($aseco['CHATPMLOG_TIMES'][0]) === 'TRUE';
            $this->settings['show_recs_range'] = strtoupper($aseco['SHOW_RECS_RANGE'][0]) === 'TRUE';
            $this->settings['recs_in_window'] = strtoupper($aseco['RECS_IN_WINDOW'][0]) === 'TRUE';
            $this->settings['rounds_in_window'] = strtoupper($aseco['ROUNDS_IN_WINDOW'][0]) === 'TRUE';
            $this->settings['writetracklist_random'] = strtoupper($aseco['WRITETRACKLIST_RANDOM'][0]) === 'TRUE';
            $this->settings['help_explanation'] = strtoupper($aseco['HELP_EXPLANATION'][0]) === 'TRUE';
            $this->settings['lists_colornicks'] = strtoupper($aseco['LISTS_COLORNICKS'][0]) === 'TRUE';
            $this->settings['lists_colortracks'] = strtoupper($aseco['LISTS_COLORTRACKS'][0]) === 'TRUE';
            $this->settings['display_checkpoints'] = strtoupper($aseco['DISPLAY_CHECKPOINTS'][0]) === 'TRUE';
            $this->settings['enable_cpsspec'] = strtoupper($aseco['ENABLE_CPSSPEC'][0]) === 'TRUE';
            $this->settings['auto_enable_cps'] = strtoupper($aseco['AUTO_ENABLE_CPS'][0]) === 'TRUE';
            $this->settings['auto_enable_dedicps'] = strtoupper($aseco['AUTO_ENABLE_DEDICPS'][0]) === 'TRUE';
            $this->settings['auto_admin_addip'] = strtoupper($aseco['AUTO_ADMIN_ADDIP'][0]) === 'TRUE';
            $this->settings['afk_force_spec'] = strtoupper($aseco['AFK_FORCE_SPEC'][0]) === 'TRUE';
            $this->settings['clickable_lists'] = strtoupper($aseco['CLICKABLE_LISTS'][0]) === 'TRUE';
            $this->settings['show_rec_logins'] = strtoupper($aseco['SHOW_REC_LOGINS'][0]) === 'TRUE';
            $this->settings['sb_stats_panels'] = strtoupper($aseco['SB_STATS_PANELS'][0]) === 'TRUE';

            $tmserver = $settings['SETTINGS']['TMSERVER'][0];

            $this->server->login = $tmserver['LOGIN'][0];
            $this->server->pass = $tmserver['PASSWORD'][0];
            $this->server->port = $tmserver['PORT'][0];
            $this->server->ip = $tmserver['IP'][0];
            $this->server->timeout = isset($tmserver['TIMEOUT'][0]) ? (int)$tmserver['TIMEOUT'][0] : null;
            if (!$this->server->timeout) {
                trigger_error('Server init timeout not specified in config.xml !', E_USER_WARNING);
            }

            $this->style = [];
            $this->panels = [
                'admin' => '',
                'donate' => '',
                'records' => '',
                'vote' => ''
            ];

            if ($this->settings['admin_client'] !== '' &&
                (preg_match('/^2\.11\.[12][0-9]$/', $this->settings['admin_client']) !== 1 ||
                 $this->settings['admin_client'] === '2.11.10')) {
                trigger_error('Invalid admin client version : ' . $this->settings['admin_client'] . ' !', E_USER_ERROR);
            }
            if ($this->settings['player_client'] !== '' &&
                (preg_match('/^2\.11\.[12][0-9]$/', $this->settings['player_client']) !== 1 ||
                 $this->settings['player_client'] === '2.11.10')) {
                trigger_error('Invalid player client version: ' . $this->settings['player_client'] . ' !', E_USER_ERROR);
            }
        } else {
            trigger_error('Could not read/parse config file ' . $config_file . ' !', E_USER_ERROR);
        }
    }

	/**
	 * Read Admin/Operator/Ability lists and apply them on the current instance.
	 */
	public function readLists(): bool {

		$adminops_file = $this->settings['adminops_file'];

		if ($lists = $this->xml_parser->parseXml($adminops_file, true, true)) {
			$this->titles = $lists['LISTS']['TITLES'][0];

			if (is_array($lists['LISTS']['ADMINS'][0])) {
				$this->admin_list = $lists['LISTS']['ADMINS'][0];
				if (empty($this->admin_list['IPADDRESS'])) {
					if (($cnt = count($this->admin_list['TMLOGIN'])) > 0)
						$this->admin_list['IPADDRESS'] = array_fill(0, $cnt, '');
				} else {
					if (count($this->admin_list['TMLOGIN']) != count($this->admin_list['IPADDRESS'])) {
						trigger_error("Admin mismatch between <tmlogin>'s and <ipaddress>'s!", E_USER_WARNING);
					}
				}
			}

			if (is_array($lists['LISTS']['OPERATORS'][0])) {
				$this->operator_list = $lists['LISTS']['OPERATORS'][0];
				if (empty($this->operator_list['IPADDRESS'])) {
					if (($cnt = count($this->operator_list['TMLOGIN'])) > 0)
						$this->operator_list['IPADDRESS'] = array_fill(0, $cnt, '');
				} else {
					if (count($this->operator_list['TMLOGIN']) != count($this->operator_list['IPADDRESS'])) {
						trigger_error("Operators mismatch between <tmlogin>'s and <ipaddress>'s!", E_USER_WARNING);
					}
				}
			}

			$this->adm_abilities = $lists['LISTS']['ADMIN_ABILITIES'][0];
			$this->op_abilities = $lists['LISTS']['OPERATOR_ABILITIES'][0];

			foreach ($this->adm_abilities as $ability => $value) {
				$this->adm_abilities[$ability][0] = strtoupper($value[0]) === 'TRUE';
			}
			foreach ($this->op_abilities as $ability => $value) {
				$this->op_abilities[$ability][0] = strtoupper($value[0]) === 'TRUE';
			}
			return true;
		} else {
			trigger_error('Could not read/parse adminops file ' . $adminops_file . ' !', E_USER_WARNING);
			return false;
		}
	}

	/**
	 * Write Admin/Operator/Ability lists to save them for future runs.
	 */
	public function writeLists(): bool {

		$adminops_file = $this->settings['adminops_file'];

		$lists = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>" . CRLF
		       . "<lists>" . CRLF
		       . "\t<titles>" . CRLF;
		foreach ($this->titles as $title => $value) {
			$lists .= "\t\t<" . strtolower($title) . ">" .
			          $value[0] . "</" . strtolower($title) . ">" . CRLF;
		}
		$lists .= "\t</titles>" . CRLF
		        . CRLF
		        . "\t<admins>" . CRLF;
		$empty = true;
		if (isset($this->admin_list['TMLOGIN'])) {
			for ($i = 0; $i < count($this->admin_list['TMLOGIN']); $i++) {
				if ($this->admin_list['TMLOGIN'][$i] != '') {
					$lists .= "\t\t<tmlogin>" . $this->admin_list['TMLOGIN'][$i] . "</tmlogin>"
					         . " <ipaddress>" . $this->admin_list['IPADDRESS'][$i] . "</ipaddress>" . CRLF;
					$empty = false;
				}
			}
		}
		if ($empty) {
			$lists .= "<!-- format:" . CRLF
			        . "\t\t<tmlogin>YOUR_ADMIN_LOGIN</tmlogin> <ipaddress></ipaddress>" . CRLF
			        . "-->" . CRLF;
		}
		$lists .= "\t</admins>" . CRLF
		        . CRLF
		        . "\t<operators>" . CRLF;
		$empty = true;
		if (isset($this->operator_list['TMLOGIN'])) {
			for ($i = 0; $i < count($this->operator_list['TMLOGIN']); $i++) {
				if ($this->operator_list['TMLOGIN'][$i] != '') {
					$lists .= "\t\t<tmlogin>" . $this->operator_list['TMLOGIN'][$i] . "</tmlogin>"
					         . " <ipaddress>" . $this->operator_list['IPADDRESS'][$i] . "</ipaddress>" . CRLF;
					$empty = false;
				}
			}
		}
		if ($empty) {
			$lists .= "<!-- format:" . CRLF
			        . "\t\t<tmlogin>YOUR_OPERATOR_LOGIN</tmlogin> <ipaddress></ipaddress>" . CRLF
			        . "-->" . CRLF;
		}
		$lists .= "\t</operators>" . CRLF
		        . CRLF
		        . "\t<admin_abilities>" . CRLF;
		foreach ($this->adm_abilities as $ability => $value) {
			$lists .= "\t\t<" . strtolower($ability) . ">" .
			          ($value[0] ? "true" : "false")
			           . "</" . strtolower($ability) . ">" . CRLF;
		}
		$lists .= "\t</admin_abilities>" . CRLF
		        . CRLF
		        . "\t<operator_abilities>" . CRLF;
		foreach ($this->op_abilities as $ability => $value) {
			$lists .= "\t\t<" . strtolower($ability) . ">" .
			          ($value[0] ? "true" : "false")
			           . "</" . strtolower($ability) . ">" . CRLF;
		}
		$lists .= "\t</operator_abilities>" . CRLF
		        . "</lists>" . CRLF;

		if (!@file_put_contents($adminops_file, $lists)) {
			trigger_error('Could not write adminops file ' . $adminops_file . ' !', E_USER_WARNING);
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Read Banned IPs list and apply it on the current instance.
	 */
	public function readIPs(): bool {

		$bannedips_file = $this->settings['bannedips_file'];

		if ($list = $this->xml_parser->parseXml($bannedips_file)) {
			$this->bannedips = $list['BAN_LIST']['IPADDRESS'] ?? [];
			return true;
		} else {
			trigger_error('Could not read/parse banned IPs file ' . $bannedips_file . ' !', E_USER_WARNING);
			return false;
		}
	}

	/**
	 * Write Banned IPs list to save it for future runs.
	 */
	public function writeIPs(): bool {

		$bannedips_file = $this->settings['bannedips_file'];
		$empty = true;

		$list = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>" . CRLF
		      . "<ban_list>" . CRLF;
		for ($i = 0; $i < count($this->bannedips); $i++) {
			if ($this->bannedips[$i] != '') {
				$list .= "\t\t<ipaddress>" . $this->bannedips[$i] . "</ipaddress>" . CRLF;
				$empty = false;
			}
		}
		if ($empty) {
			$list .= "<!-- format:" . CRLF
			       . "\t\t<ipaddress>xx.xx.xx.xx</ipaddress>" . CRLF
			       . "-->" . CRLF;
		}
		$list .= "</ban_list>" . CRLF;

		if (!@file_put_contents($bannedips_file, $list)) {
			trigger_error('Could not write banned IPs file ' . $bannedips_file . ' !', E_USER_WARNING);
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Loads files in the plugins directory.
	 */
	public function loadPlugins(): void {

		if ($plugins = $this->xml_parser->parseXml('plugins.xml')) {
			if (!empty($plugins['ASECO_PLUGINS']['PLUGIN'])) {
				foreach ($plugins['ASECO_PLUGINS']['PLUGIN'] as $plugin) {
					$this->console_text('[XAseco] Load plugin [' . $plugin . ']');
					require_once 'plugins/' . $plugin;
					$this->plugins[] = $plugin;
				}
			}
		} else {
			trigger_error('Could not read/parse plugins list plugins.xml !', E_USER_ERROR);
		}
	}

/**
 * Runs the server.
 */
public function run(string $config_file): void {

    $this->console_text('[XAseco] Load settings [{1}]', $config_file);
    $this->loadSettings($config_file);

    $this->console_text('[XAseco] Load admin/ops lists [{1}]', $this->settings['adminops_file']);
    $this->readLists();

    $this->console_text('[XAseco] Load banned IPs list [{1}]', $this->settings['bannedips_file']);
    $this->readIPs();

    $this->console_text('[XAseco] Load plugins list [plugins.xml]');
    $this->loadPlugins();

    if (!$this->connect()) {
        trigger_error('Connection could not be established!', E_USER_ERROR);
    }

    $this->console('Connection established successfully!');
    if ($this->settings['lock_password'] != '') {
        $this->console_text("[XAseco] Locked admin commands & features with password '{1}'", $this->settings['lock_password']);
    }

    $this->client->query('GetVersion');
    $response['version'] = $this->client->getResponse();
    $this->server->game = $response['version']['Name'];
    $this->server->version = $response['version']['Version'];
    $this->server->build = $response['version']['Build'];

    $this->releaseEvent('onStartup', null);

    $this->serverSync();

    if ($this->server->getGame() != 'TMF') {
        $this->registerChatCommands();
        if ($this->settings['cheater_action'] == 1) {
            $this->settings['cheater_action'] = 0;
        }
    }

    $this->sendHeader();

    if ($this->currstatus == 100) {
        $this->console_text('[XAseco] Waiting for the server to start a challenge');
    } else {
        $this->beginRace(false);
    }

    $this->startup_phase = false;
    while (true) {
        $starttime = microtime(true);
        $this->executeCallbacks();
        $this->executeCalls();
        $this->releaseEvent('onMainLoop', null);

        $this->currsecond = time();
        if ($this->prevsecond != $this->currsecond) {
            $this->prevsecond = $this->currsecond;
            $this->releaseEvent('onEverySecond', null);
        }

        $endtime = microtime(true);
        $delay = 100000 - ($endtime - $starttime) * 1000000;
        if ($delay > 0) {
            usleep((int)$delay);  // Cast $delay to an integer
        }

        set_time_limit((int)$this->settings['script_timeout']);
    }

    $this->client->Terminate();
}

	/**
	 * Authenticates XASECO at the server.
	 */
	public function connect(): bool {

		if ($this->server->ip && $this->server->port && $this->server->login && $this->server->pass) {
			$this->console('Try to connect to TM dedicated server on {1}:{2} timeout {3}s',
			               $this->server->ip, $this->server->port,
			               $this->server->timeout !== null ? $this->server->timeout : 0);

			if (!$this->client->InitWithIp($this->server->ip, $this->server->port, $this->server->timeout)) {
				trigger_error('[' . $this->client->getErrorCode() . '] InitWithIp - ' . $this->client->getErrorMessage(), E_USER_WARNING);
				return false;
			}

			$this->console("Try to authenticate with login '{1}' and password '{2}'",
			               $this->server->login, $this->server->pass);

			if ($this->server->login != 'SuperAdmin') {
				trigger_error("Invalid login '" . $this->server->login . "' - must be 'SuperAdmin' in config.xml!", E_USER_WARNING);
				return false;
			}
			if ($this->server->pass == 'SuperAdmin') {
				trigger_error("Insecure password '" . $this->server->pass . "' - should be changed in dedicated config and config.xml!", E_USER_WARNING);
			}

			if (!$this->client->query('Authenticate', $this->server->login, $this->server->pass)) {
				trigger_error('[' . $this->client->getErrorCode() . '] Authenticate - ' . $this->client->getErrorMessage(), E_USER_WARNING);
				return false;
			}

			$this->client->query('EnableCallbacks', true);

			$this->waitServerReady();

			return true;
		} else {
			return false;
		}
	}

	/**
	 * Waits for the server to be ready (status 4, 'Running - Play')
	 */
	public function waitServerReady(): void {

		$this->client->query('GetStatus');
		$status = $this->client->getResponse();
		if ($status['Code'] != 4) {
			$this->console("Waiting for dedicated server to reach status 'Running - Play'...");
			$this->console('Status: ' . $status['Name']);
			$timeout = 0;
			$laststatus = $status['Name'];
			while ($status['Code'] != 4) {
				sleep(1);
				$this->client->query('GetStatus');
				$status = $this->client->getResponse();
				if ($laststatus != $status['Name']) {
					$this->console('Status: ' . $status['Name']);
					$laststatus = $status['Name'];
				}
				if (isset($this->server->timeout) && $timeout++ > $this->server->timeout) {
					trigger_error('Timed out while waiting for dedicated server!', E_USER_ERROR);
				}
			}
		}
	}

	/**
	 * Initializes the server and the player list.
	 * Reads a list of the players who are on the server already,
	 * and loads all server variables.
	 */
	public function serverSync(): void {

		if (strlen($this->server->build) == 0 ||
		    ($this->server->getGame() != 'TMF' && strcmp($this->server->build, TMN_BUILD) < 0) ||
		    ($this->server->getGame() == 'TMF' && strcmp($this->server->build, TMF_BUILD) < 0)) {
			trigger_error("Obsolete server build '" . $this->server->build . "' - must be " .
			              ($this->server->getGame() == 'TMF' ? "at least '" . TMF_BUILD . "'!" : "'" . TMN_BUILD . "'!"), E_USER_ERROR);
		}

		$this->server->id = 0;
		$this->server->rights = false;
		$this->server->isrelay = false;
		$this->server->relaymaster = null;
		$this->server->relayslist = [];
		$this->server->gamestate = Server::RACE;
		$this->server->packmask = '';
		if ($this->server->getGame() == 'TMF') {
			$this->client->query('GetSystemInfo');
			$response['system'] = $this->client->getResponse();
			$this->server->serverlogin = $response['system']['ServerLogin'];

			$this->client->query('GetDetailedPlayerInfo', $this->server->serverlogin);
			$response['info'] = $this->client->getResponse();
			$this->server->id = $response['info']['PlayerId'];
			$this->server->nickname = $response['info']['NickName'];
			$this->server->zone = substr($response['info']['Path'], 6);
			$this->server->rights = ($response['info']['OnlineRights'] == 3);

			$this->client->query('GetLadderServerLimits');
			$response['ladder'] = $this->client->getResponse();
			$this->server->laddermin = $response['ladder']['LadderServerLimitMin'];
			$this->server->laddermax = $response['ladder']['LadderServerLimitMax'];

			$this->client->query('IsRelayServer');
			$this->server->isrelay = ($this->client->getResponse() > 0);
			if ($this->server->isrelay) {
				$this->client->query('GetMainServerPlayerInfo', 1);
				$this->server->relaymaster = $this->client->getResponse();
			}

			$this->client->query('GetServerPackMask');
			$this->server->packmask = $this->client->getResponse();

			$this->client->query('SendHideManialinkPage');
		}

		$this->client->query('GetCurrentGameInfo', $this->server->getGame() == 'TMF' ? 1 : 0);
		$response['gameinfo'] = $this->client->getResponse();
		$this->server->gameinfo = new Gameinfo($response['gameinfo']);

		$this->client->query('GetStatus');
		$response['status'] = $this->client->getResponse();
		$this->currstatus = $response['status']['Code'];

		$this->client->query('GameDataDirectory');
		$this->server->gamedir = $this->client->getResponse();
		$this->client->query('GetTracksDirectory');
		$this->server->trackdir = $this->client->getResponse();

		$this->getServerOptions();

		$this->releaseEvent('onSync', null);

		$this->client->query('GetPlayerList', 300, 0, $this->server->getGame() == 'TMF' ? 2 : 0);
		$response['playerlist'] = $this->client->getResponse();

		if (!empty($response['playerlist'])) {
			foreach ($response['playerlist'] as $player) {
				$this->playerConnect([$player['Login'], '']);
			}
		}
	}

	/**
	 * Sends program header to console and ingame chat.
	 */
	public function sendHeader(): void {

		$this->console_text('###############################################################################');
		$this->console_text('  XASECO v' . XASECO_VERSION . ' running on {1}:{2}', $this->server->ip, $this->server->port);
		if ($this->server->getGame() == 'TMF') {
			$this->console_text('  Name   : {1} - {2}', stripColors($this->server->name, false), $this->server->serverlogin);
			if ($this->server->isrelay) {
				$this->console_text('  Relays : {1} - {2}', stripColors($this->server->relaymaster['NickName'], false), $this->server->relaymaster['Login']);
			}
			$this->console_text('  Game   : {1} {2} - {3} - {4}', $this->server->game,
			                    $this->server->rights ? 'United' : 'Nations',
			                    $this->server->packmask, $this->server->gameinfo->getMode());
		} else {
			$this->console_text('  Name   : {1}', stripColors($this->server->name, false));
			$this->console_text('  Game   : {1} - {2}', $this->server->game, $this->server->gameinfo->getMode());
		}
		$this->console_text('  Version: {1} / {2}', $this->server->version, $this->server->build);
		$this->console_text('  Authors: Florian Schnell & Assembler Maniac');
		$this->console_text('  Re-Authored: Xymph');
		$this->console_text('###############################################################################');

		$startup_msg = formatText($this->getChatMessage('STARTUP'),
		                          XASECO_VERSION,
		                          $this->server->ip, $this->server->port);
		$this->client->query('ChatSendServerMessage', $this->formatColors($startup_msg));
	}

	/**
	 * Gets callbacks from the TM Dedicated Server and reacts on them.
	 */
	public function executeCallbacks(): array|false {

		$this->client->resetError();
		$this->client->readCB();

		$calls = $this->client->getCBResponses();
		if ($this->client->isError()) {
			trigger_error('ExecCallbacks XMLRPC Error [' . $this->client->getErrorCode() . '] - ' . $this->client->getErrorMessage(), E_USER_ERROR);
		}

		if (!empty($calls)) {
			while ($call = array_shift($calls)) {
				switch ($call[0]) {
					case 'TrackMania.PlayerConnect':
						$this->playerConnect($call[1]);
						break;

					case 'TrackMania.PlayerDisconnect':
						$this->playerDisconnect($call[1]);
						break;

					case 'TrackMania.PlayerChat':
						$this->playerChat($call[1]);
						$this->releaseEvent('onChat', $call[1]);
						break;

					case 'TrackMania.PlayerServerMessageAnswer':
						$this->playerServerMessageAnswer($call[1]);
						break;

					case 'TrackMania.PlayerCheckpoint':
						if (!$this->server->isrelay) {
							$this->releaseEvent('onCheckpoint', $call[1]);
						}
						break;

					case 'TrackMania.PlayerFinish':
						$this->playerFinish($call[1]);
						break;

					case 'TrackMania.BeginRace':
						if ($this->server->getGame() != 'TMF') {
							$this->beginRace($call[1]);
						}
						break;

					case 'TrackMania.EndRace':
						if ($this->server->getGame() != 'TMF') {
							$this->endRace($call[1]);
						}
						break;

					case 'TrackMania.BeginRound':
						$this->beginRound();
						break;

					case 'TrackMania.StatusChanged':
						$this->prevstatus = $this->currstatus;
						$this->currstatus = $call[1][0];
						if ($this->server->getGame() == 'TMF') {
							if ($this->currstatus == 3 || $this->currstatus == 5) {
								$this->client->query('GetWarmUp');
								$this->warmup_phase = $this->client->getResponse();
							}
						} else {
							$this->warmup_phase = false;
						}
						if ($this->server->getGame() != 'TMF') {
							if ($this->prevstatus == 4 && ($this->currstatus == 3 || $this->currstatus == 5)) {
								$this->endRound();
							}
						}
						if ($this->currstatus == 4) {
							$this->runningPlay();
						}
						$this->releaseEvent('onStatusChangeTo' . $this->currstatus, $call[1]);
						break;

					case 'TrackMania.EndRound':
						$this->endRound();
						break;

					case 'TrackMania.BeginChallenge':
						$this->beginRace($call[1]);
						break;

					case 'TrackMania.EndChallenge':
						$this->endRace($call[1]);
						break;

					case 'TrackMania.PlayerManialinkPageAnswer':
						$this->releaseEvent('onPlayerManialinkPageAnswer', $call[1]);
						break;

					case 'TrackMania.BillUpdated':
						$this->releaseEvent('onBillUpdated', $call[1]);
						break;

					case 'TrackMania.ChallengeListModified':
						$this->releaseEvent('onChallengeListModified', $call[1]);
						break;

					case 'TrackMania.PlayerInfoChanged':
						$this->playerInfoChanged($call[1][0]);
						break;

					case 'TrackMania.PlayerIncoherence':
						$this->releaseEvent('onPlayerIncoherence', $call[1]);
						break;

					case 'TrackMania.TunnelDataReceived':
						$this->releaseEvent('onTunnelDataReceived', $call[1]);
						break;

					case 'TrackMania.Echo':
						$this->releaseEvent('onEcho', $call[1]);
						break;

					case 'TrackMania.ManualFlowControlTransition':
						$this->releaseEvent('onManualFlowControlTransition', $call[1]);
						break;

					case 'TrackMania.VoteUpdated':
						$this->releaseEvent('onVoteUpdated', $call[1]);
						break;

					default:
						// do nothing
				}
			}
			return $calls;
		} else {
			return false;
		}
	}

	/**
	 * Adds calls to a multiquery.
	 * It's possible to set a callback function which
	 * will be executed on incoming response.
	 * You can also set an ID to read response later on.
	 */
	public function addCall(string $call, array $params = [], int|string $id = 0, callable $callback_func = null): void {

		$index = $this->client->addCall($call, $params);
		$rpc_call = new RPCCall($id, $index, $callback_func, [$call, $params]);
		$this->rpc_calls[] = $rpc_call;
	}

	/**
	 * Executes a multicall and gets responses.
	 * Saves responses in array with IDs as keys.
	 */
	public function executeCalls(): bool {

		$this->rpc_responses = [];

		if (empty($this->client->calls)) {
			return true;
		}

		$this->client->resetError();
		$tmpcalls = $this->client->calls;
		if ($this->client->multiquery()) {
			if ($this->client->isError()) {
				$this->console_text(print_r($tmpcalls, true));
				trigger_error('ExecCalls XMLRPC Error [' . $this->client->getErrorCode() . '] - ' . $this->client->getErrorMessage(), E_USER_ERROR);
			}

			$responses = $this->client->getResponse();

			foreach ($this->rpc_calls as $call) {
				$err = false;
				if (isset($responses[$call->index]['faultString'])) {
					$this->rpcErrorResponse($responses[$call->index]);
					print_r($call->call);
					$err = true;
				}

				if ($call->id) {
					$this->rpc_responses[$call->id] = $responses[$call->index][0];
				}

				if (is_array($call->callback)) {
				// It's a method call on an object
					if (method_exists($call->callback[0], $call->callback[1])) {
						call_user_func($call->callback, $responses[$call->index][0]);
					}
				} else {
					// It's a global function
					if (function_exists($call->callback)) {
						call_user_func($call->callback, $responses[$call->index][0]);
					}
				}

			}
		}

		$this->rpc_calls = [];
		return true;
	}

	/**
	 * Documents RPC Errors.
	 */
	public function rpcErrorResponse(array $response): void {

		$this->console_text('[RPC Error ' . $response['faultCode'] . '] ' . $response['faultString']);
	}

	/**
	 * Registers functions which are called on specific events.
	 */
	public function registerEvent(string $event_type, callable $callback_func): void {

		$this->events[$event_type][] = $callback_func;
	}

	/**
	 * Executes the functions which were registered for specified events.
	 */
	public function releaseEvent(string $event_type, mixed $func_param): void {

		if (!empty($this->events[$event_type])) {
			foreach ($this->events[$event_type] as $func_name) {
				if (is_callable($func_name)) {
					call_user_func($func_name, $this, $func_param);
				}
			}
		}
	}

	/**
	 * Stores a new user command.
	 */
	public function addChatCommand(string $command_name, string $command_help, bool $command_is_admin = false): void {

		$chat_command = new ChatCommand($command_name, $command_help, $command_is_admin);
		$this->chat_commands[] = $chat_command;
	}

	/**
	 * Registers all chat commands with the server.
	 */
	public function registerChatCommands(): void {

		$this->client->query('CleanChatCommand');

		if (isset($this->chat_commands)) {
			foreach ($this->chat_commands as $command) {
				if (!$command->isadmin) {
					if ($this->debug) {
						$this->console_text('register chat command: ' . $command->name);
					}
					$this->client->query('AddChatCommand', $command->name);
				}
			}
		}
	}

	/**
	 * When a round is started, signal the event.
	 */
	public function beginRound(): void {

		$this->console_text('Begin Round');
		$this->releaseEvent('onBeginRound', null);
	}

	/**
	 * When a round is ended, signal the event.
	 */
	public function endRound(): void {

		$this->console_text('End Round');
		$this->releaseEvent('onEndRound', null);
	}

	/**
	 * When a TMF player's info changed, signal the event.
	 * Fields: Login, NickName, PlayerId, TeamId, SpectatorStatus, LadderRanking, Flags
	 */
	public function playerInfoChanged(array $playerinfo): void {

		if ($this->server->isrelay && floor($playerinfo['Flags'] / 10000) % 10 != 0) {
			return;
		}

		if (!$player = $this->server->players->getPlayer($playerinfo['Login'])) {
			return;
		}

		if ($playerinfo['LadderRanking'] > 0) {
			$player->ladderrank = $playerinfo['LadderRanking'];
			$player->isofficial = true;
		} else {
			$player->isofficial = false;
		}

		$player->prevstatus = $player->isspectator;
		$player->isspectator = ($playerinfo['SpectatorStatus'] % 10) != 0;

		$this->releaseEvent('onPlayerInfoChanged', $playerinfo);
	}

	/**
	 * When a new track is started we have to get information
	 * about the new track and so on.
	 */
	public function runningPlay(): void {
		// request information about the new challenge
		// ... and callback to function newChallenge()
	}

	/**
	 * When a new race is started we have to get information
	 * about the new track and so on.
	 */
	public function beginRace($race): void {

		if ($this->server->getGame() == 'TMF' && $race) {
			$this->warmup_phase = $race[1];
		}

		if (!$race) {
			$this->addCall('GetCurrentChallengeInfo', [], 0, [$this, 'newChallenge']);
		} else {
			$this->newChallenge($race[0]);
		}
	}

	/**
	 * Reacts on new challenges.
	 * Gets record to current challenge etc.
	 */
	public function newChallenge(array $challenge): void {

		$this->server->gamestate = Server::RACE;
		if ($this->restarting == 0) {
			$this->console_text('Begin Challenge');
		}

		$this->client->query('GetCurrentGameInfo', ($this->server->getGame() == 'TMF' ? 1 : 0));
		$gameinfo = $this->client->getResponse();
		$this->server->gameinfo = new Gameinfo($gameinfo);

		$this->changingmode = false;
		if ($this->server->getGame() == 'TMF' && $this->restarting > 0) {
			if ($this->restarting == 2) {
				$this->restarting = 0;
			} else {
				$this->restarting = 0;
				$this->releaseEvent('onRestartChallenge2', $challenge);
				return;
			}
		}

		$this->getServerOptions();

		$this->server->records->clear();

		$challenge_item = new Challenge($challenge);

		if ($this->server->getGame() == 'TMF' && $challenge_item->laprace &&
		    ($this->server->gameinfo->mode == Gameinfo::RNDS ||
		     $this->server->gameinfo->mode == Gameinfo::TEAM ||
		     $this->server->gameinfo->mode == Gameinfo::CUP)) {
			$challenge_item->forcedlaps = $this->server->gameinfo->forcedlaps;
		}

		$challenge_item->gbx = new GBXChallMapFetcher(true);
		try {
			$challenge_item->gbx->processFile($this->server->trackdir . $challenge_item->filename);
		} catch (Exception $e) {
			trigger_error($e->getMessage(), E_USER_WARNING);
		}
		$challenge_item->tmx = findTMXdata($challenge_item->uid, $challenge_item->environment, $challenge_item->gbx->exeVer, true);

		$this->releaseEvent('onNewChallenge', $challenge_item);

		$this->console('track changed [{1}] >> [{2}]',
		               stripColors($this->server->challenge->name, false),
		               stripColors($challenge_item->name, false));

		if (!$this->server->isrelay) {
			$cur_record = $this->server->records->getRecord(0);
			if ($cur_record !== false && $cur_record->score > 0) {
				$score = ($this->server->gameinfo->mode == Gameinfo::STNT ?
				          str_pad($cur_record->score, 5, ' ', STR_PAD_LEFT) :
				          formatTime($cur_record->score));

				$this->console('current record on {1} is {2} and held by {3}',
				               stripColors($challenge_item->name, false),
				               trim($score),
				               stripColors($cur_record->player->nickname, false));

				$message = formatText($this->getChatMessage('RECORD_CURRENT'),
				                      stripColors($challenge_item->name),
				                      trim($score),
				                      stripColors($cur_record->player->nickname));
			} else {
				$score = ($this->server->gameinfo->mode == Gameinfo::STNT) ? '  ---' : '   --.--';

				$this->console('currently no record on {1}',
				               stripColors($challenge_item->name, false));

				$message = formatText($this->getChatMessage('RECORD_NONE'),
				                      stripColors($challenge_item->name));
			}

			if (function_exists('setRecordsPanel')) {
				setRecordsPanel('local', $score);
			}

			if (($this->settings['show_recs_before'] & 1) == 1) {
				if (($this->settings['show_recs_before'] & 4) == 4 && function_exists('send_window_message')) {
					send_window_message($this, $message, false);
				} else {
					$this->client->query('ChatSendServerMessage', $this->formatColors($message));
				}
			}
		}

		$this->server->challenge = $challenge_item;

		$this->releaseEvent('onNewChallenge2', $challenge_item);

		if (($this->settings['show_recs_before'] & 2) == 2 && function_exists('show_trackrecs')) {
			show_trackrecs($this, false, 1, $this->settings['show_recs_before']);
		}
	}

	/**
	 * End of current race.
	 * Write records to database and/or display final statistics.
	 */
	public function endRace(array $race): void {

		if ($this->server->getGame() == 'TMF' && $race[4]) {
			$this->restarting = 1;
			if ($this->changingmode) {
				$this->restarting = 2;
			} else {
				foreach ($race[0] as $pl) {
					if ($pl['BestTime'] > 0 || $pl['Score'] > 0) {
						$this->restarting = 2;
						break;
					}
				}
			}
			if ($this->restarting == 2) {
				$this->console_text('Restart Challenge (with ChatTime)');
			} else {
				$this->console_text('Restart Challenge (instant)');
				$this->releaseEvent('onRestartChallenge', $race);
				return;
			}
		}

		$this->server->gamestate = Server::SCORE;
		if ($this->restarting == 0) {
			$this->console_text('End Challenge');
		}

		if (($this->settings['show_recs_after'] & 2) == 2 && function_exists('show_trackrecs')) {
			show_trackrecs($this, false, 3, $this->settings['show_recs_after']);
		} elseif (($this->settings['show_recs_after'] & 1) == 1) {
			$records = '';

			if ($this->server->records->count() == 0) {
				$message = formatText($this->getChatMessage('RANKING_NONE'),
				                      stripColors($this->server->challenge->name),
				                      'after');
			} else {
				$message = formatText($this->getChatMessage('RANKING'),
				                      stripColors($this->server->challenge->name),
				                      'after');

				for ($i = 0; $i < 5; $i++) {
					$cur_record = $this->server->records->getRecord($i);

					if ($cur_record !== false && $cur_record->score > 0) {
						$record_msg = formatText($this->getChatMessage('RANKING_RECORD_NEW'),
						                         $i+1,
						                         stripColors($cur_record->player->nickname),
						                         ($this->server->gameinfo->mode == Gameinfo::STNT ?
						                          $cur_record->score : formatTime($cur_record->score)));
						$records .= $record_msg;
					}
				}
			}

			if ($records != '') {
				$records = substr($records, 0, strlen($records)-2);  // strip trailing ", "
				$message .= LF . $records;
			}

			if (($this->settings['show_recs_after'] & 4) == 4 && function_exists('send_window_message')) {
				send_window_message($this, $message, true);
			} else {
				$this->client->query('ChatSendServerMessage', $this->formatColors($message));
			}
		}

		if (!$this->server->isrelay) {
			$this->endRaceRanking($race[0]);
		}

		$this->releaseEvent('onEndRace1', $race);
		$this->releaseEvent('onEndRace', $race);
	}

	/**
	 * Check out who won the current track and increment his/her wins by one.
	 */
	public function endRaceRanking(array $ranking): void {

		if (isset($ranking[0]['Login']) &&
		    ($player = $this->server->players->getPlayer($ranking[0]['Login'])) !== false) {
			if ($ranking[0]['Rank'] == 1 && count($ranking) > 1 &&
			    ($this->server->gameinfo->mode == Gameinfo::STNT ?
			     ($ranking[0]['Score'] > 0) : ($ranking[0]['BestTime'] > 0))) {
				$player->newwins++;

				$this->console('{1} won for the {2}. time!',
				               $player->login, $player->getWins());

				if ($player->getWins() % $this->settings['global_win_multiple'] == 0) {
					$message = formatText($this->getChatMessage('WIN_MULTI'),
					                      stripColors($player->nickname), $player->getWins());

					$this->client->query('ChatSendServerMessage', $this->formatColors($message));
				} else {
					$message = formatText($this->getChatMessage('WIN_NEW'),
					                      $player->getWins());

					$this->client->query('ChatSendServerMessageToLogin', $this->formatColors($message), $player->login);
				}

				$this->releaseEvent('onPlayerWins', $player);
			}
		}
	}

	/**
	 * Handles connections of new players.
	 */
	public function playerConnect(array $player): void {

		$login = $player[0];
		if ($this->server->getGame() == 'TMF') {
			$this->client->query('GetDetailedPlayerInfo', $login);
			$playerd = $this->client->getResponse();
			$this->client->query('GetPlayerInfo', $login, 1);
		} else {
			$this->client->query('GetPlayerInfo', $login);
		}
		$player = $this->client->getResponse();

		if (isset($player['Flags']) && floor($player['Flags'] / 100000) % 10 != 0) {
			if (!$this->server->isrelay && $player['Login'] != $this->server->serverlogin) {
				$this->server->relayslist[$player['Login']] = $player;

				$this->console('<<< relay server {1} ({2}) connected', $player['Login'],
				               stripColors($player['NickName'], false));
			}
		} elseif ($this->server->isrelay && floor($player['Flags'] / 10000) % 10 != 0) {
			// ignore
		} else {
			$ipaddr = isset($playerd['IPAddress']) ? preg_replace('/:\d+/', '', $playerd['IPAddress']) : '';

			if (!isset($player['Login']) || $player['Login'] == '') {
				$message = str_replace('{br}', LF, $this->getChatMessage('CONNECT_ERROR'));
				$message = $this->formatColors($message);
				$this->client->query('ChatSendServerMessageToLogin', str_replace(LF.LF, LF, $message), $login);
				if ($this->server->getGame() == 'TMN') {
					$this->client->query('SendDisplayServerMessageToLogin', $login, $message, 'OK', '', 0);
				}
				sleep(5);
				if ($this->server->getGame() == 'TMF') {
					$this->client->addCall('Kick', [$login, $this->formatColors($this->getChatMessage('CONNECT_DIALOG'))]);
				} else {
					$this->client->addCall('Kick', [$login]);
				}
				$this->console('GetPlayerInfo failed for ' . $login . ' -- notified & kicked');
				return;
			} elseif (!empty($this->bannedips) && in_array($ipaddr, $this->bannedips)) {
				$message = str_replace('{br}', LF, $this->getChatMessage('BANIP_ERROR'));
				$message = $this->formatColors($message);
				$this->client->query('ChatSendServerMessageToLogin', str_replace(LF.LF, LF, $message), $login);
				if ($this->server->getGame() == 'TMN') {
					$this->client->query('SendDisplayServerMessageToLogin', $login, $message, 'OK', '', 0);
				}
				sleep(5);
				if ($this->server->getGame() == 'TMF') {
					$this->client->addCall('Ban', [$login, $this->formatColors($this->getChatMessage('BANIP_DIALOG'))]);
				} else {
					$this->client->addCall('Ban', [$login]);
				}
				$this->console('Player ' . $login . ' banned from ' . $ipaddr . ' -- notified & kicked');
				return;
			} elseif ($this->server->getGame() == 'TMF') {
				$version = str_replace(')', '', preg_replace('/.*\(/', '', $playerd['ClientVersion']));
				if ($version == '') $version = '2.11.11';
				$message = str_replace('{br}', LF, $this->getChatMessage('CLIENT_ERROR'));

				if ($this->settings['player_client'] != '' &&
				    strcmp($version, $this->settings['player_client']) < 0) {
					$this->client->query('ChatSendServerMessageToLogin', $this->formatColors($message), $login);
					sleep(5);
					$this->client->addCall('Kick', [$login, $this->formatColors($this->getChatMessage('CLIENT_DIALOG'))]);
					$this->console('Obsolete player client version ' . $version . ' for ' . $login . ' -- notified & kicked');
					return;
				}

				if ($this->settings['admin_client'] != '' && $this->isAnyAdminL($player['Login']) &&
				    strcmp($version, $this->settings['admin_client']) < 0) {
					$this->client->query('ChatSendServerMessageToLogin', $this->formatColors($message), $login);
					sleep(5);
					$this->client->addCall('Kick', [$login, $this->formatColors($this->getChatMessage('CLIENT_DIALOG'))]);
					$this->console('Obsolete admin client version ' . $version . ' for ' . $login . ' -- notified & kicked');
					return;
				}
			}

			if ($this->server->getGame() == 'TMN' && !isLANLogin($login) &&
			    $player['LadderStats']['TeamName'] == '') {
				$data = new TMNDataFetcher($login, false);
				if ($data->teamname != '') {
					$player['LadderStats']['TeamName'] = $data->teamname;
				}
			}

			$player_item = new Player($this->server->getGame() == 'TMF' ? $playerd : $player);
			$player_item->style = $this->style;
			$player_item->panels['admin'] = $this->panels['admin'];
			$player_item->panels['donate'] = $this->panels['donate'];
			$player_item->panels['records'] = $this->panels['records'];
			$player_item->panels['vote'] = $this->panels['vote'];

			$this->server->players->addPlayer($player_item);

			$this->console('<< player {1} joined the game [{2} : {3} : {4} : {5} : {6}]',
			               $player_item->pid,
			               $player_item->login,
			               $player_item->nickname,
			               $player_item->nation,
			               $player_item->ladderrank,
			               $player_item->ip);

			$message = formatText($this->getChatMessage('WELCOME'),
			                      stripColors($player_item->nickname),
			                      $this->server->name, XASECO_VERSION);

			if ($this->server->getGame() == 'TMF') {
				$message = preg_replace('/XASECO.+' . XASECO_VERSION . '/', '$l[' . XASECO_TMN . ']$0$l', $message);
			}

			if ($this->settings['welcome_msg_window']) {
				if ($this->server->getGame() == 'TMF') {
					$message = str_replace('{#highlite}', '{#message}', $message);
					$message = preg_split('/{br}/', $this->formatColors($message));
					foreach ($message as &$line) {
						$line = [$line];
					}
					display_manialink($player_item->login, '',
					                  ['Icons64x64_1', 'Inbox'], $message,
					                  [1.2], 'OK');
				} else {
					$message = str_replace('{br}', LF, $this->formatColors($message));
					$this->client->query('SendDisplayServerMessageToLogin', $player_item->login, $message, 'OK', '', 0);
				}
			} else {
				$message = str_replace('{br}', LF, $this->formatColors($message));
				$this->client->query('ChatSendServerMessageToLogin', str_replace(LF.LF, LF, $message), $player_item->login);
			}

			$cur_record = $this->server->records->getRecord(0);
			if ($cur_record !== false && $cur_record->score > 0) {
				$message = formatText($this->getChatMessage('RECORD_CURRENT'),
				                      stripColors($this->server->challenge->name),
				                      ($this->server->gameinfo->mode == Gameinfo::STNT ?
				                       $cur_record->score : formatTime($cur_record->score)),
				                      stripColors($cur_record->player->nickname));
			} else {
				$message = formatText($this->getChatMessage('RECORD_NONE'),
				                      stripColors($this->server->challenge->name));
			}

			if (($this->settings['show_recs_before'] & 2) == 2 && function_exists('show_trackrecs')) {
				show_trackrecs($this, $player_item->login, 1, 0);
			} elseif (($this->settings['show_recs_before'] & 1) == 1) {
				$this->client->query('ChatSendServerMessageToLogin', $this->formatColors($message), $player_item->login);
			}

			$this->releaseEvent('onPlayerConnect', $player_item);
			$this->releaseEvent('onPlayerConnect2', $player_item);
		}
	}

	/**
	 * Handles disconnections of players.
	 */
	public function playerDisconnect(array $player): void {

		if (!$this->server->isrelay && array_key_exists($player[0], $this->server->relayslist)) {
			$this->console('>>> relay server {1} ({2}) disconnected', $player[0],
			               stripColors($this->server->relayslist[$player[0]]['NickName'], false));

			unset($this->server->relayslist[$player[0]]);
			return;
		}

		if (!$player_item = $this->server->players->removePlayer($player[0])) {
			return;
		}

		$this->console('>> player {1} left the game [{2} : {3} : {4}]',
		               $player_item->pid,
		               $player_item->login,
		               $player_item->nickname,
		               formatTimeH($player_item->getTimeOnline() * 1000, false));

		$this->releaseEvent('onPlayerDisconnect', $player_item);
	}

/**
 * Handles clicks on server messageboxes.
 */
function playerServerMessageAnswer($answer) {
    if ($answer[2]) {
        $this->releaseEvent('onPlayerServerMessageAnswer', $answer);
    }
}

/**
 * Player reaches finish.
 */
function playerFinish($finish) {
    if ($this->server->challenge->name == '' || $finish[0] == 0) return;
    if ($this->server->isrelay || $this->currstatus != 4) return;

    if ((!$player = $this->server->players->getPlayer($finish[1])) || $player->login == '') return;

    $finish_item = new Record();
    $finish_item->player = $player;
    $finish_item->score = $finish[2];
    $finish_item->date = strftime('%Y-%m-%d %H:%M:%S');
    $finish_item->new = false;
    $finish_item->challenge = clone $this->server->challenge;
    unset($finish_item->challenge->gbx, $finish_item->challenge->tmx);

    $this->releaseEvent('onPlayerFinish1', $finish_item);
    $this->releaseEvent('onPlayerFinish', $finish_item);
}

/**
 * Receives chat messages and reacts on them.
 */
function playerChat($chat) {
    if ($chat[1] == '' || $chat[1] == '???') {
        trigger_error('playerUid ' . $chat[0] . ' has login [' . $chat[1] . ']!', E_USER_WARNING);
        $this->console('playerUid {1} attempted to use chat command "{2}"', $chat[0], $chat[2]);
        return;
    }

    if ($this->server->isrelay && $chat[1] == $this->server->relaymaster['Login']) return;

    $command = $chat[2];
    if ($command != '' && $command[0] == '/') {
        $command = substr($command, 1);
        $params = explode(' ', $command, 2);
        $translated_name = str_replace(['+', '-'], ['plus', 'dash'], $params[0]);

        if (function_exists('chat_' . $translated_name)) {
            if (isset($params[1])) $params[1] = trim($params[1]);
            else $params[1] = '';

            if (($author = $this->server->players->getPlayer($chat[1])) && $author->login != '') {
                $this->console('player {1} used chat command "/{2} {3}"', $chat[1], $params[0], $params[1]);

                $chat_command = ['author' => $author, 'params' => $params[1]];
                call_user_func('chat_' . $translated_name, $this, $chat_command);
            } else {
                trigger_error('Player object for \'' . $chat[1] . '\' not found!', E_USER_WARNING);
                $this->console('player {1} attempted to use chat command "/{2} {3}"', $chat[1], $params[0], $params[1]);
            }
        } else {
            if ($params[0] == 'version' || ($params[0] == 'serverlogin' && $this->server->getGame() == 'TMF')) {
                $this->console('player {1} used built-in command "/{2}"', $chat[1], $command);
            } else {
                if ($this->settings['log_all_chat']) {
                    if ($chat[0] != $this->server->id) {
                        $this->console('({1}) {2}', $chat[1], stripColors($chat[2], false));
                    }
                }
            }
        }
    } else {
        if ($this->settings['log_all_chat']) {
            if ($chat[0] != $this->server->id && $chat[2] != '') {
                $this->console('({1}) {2}', $chat[1], stripColors($chat[2], false));
            }
        }
    }
}

/**
 * Gets the specified chat message out of the settings file.
 */
function getChatMessage($name) {
    return htmlspecialchars_decode($this->chat_messages[$name][0]);
}

/**
 * Checks if an admin is allowed to perform this ability.
 */
function allowAdminAbility($ability) {
    $ability = strtoupper($ability);
    return $this->adm_abilities[$ability][0] ?? false;
}

/**
 * Checks if an operator is allowed to perform this ability.
 */
function allowOpAbility($ability) {
    $ability = strtoupper($ability);
    return $this->op_abilities[$ability][0] ?? false;
}

/**
 * Checks if the given player is allowed to perform this ability.
 */
function allowAbility($player, $ability) {
    if ($this->settings['lock_password'] != '' && !$player->unlocked) return false;
    if ($this->isMasterAdmin($player)) return true;
    if ($this->isAdmin($player)) return $this->allowAdminAbility($ability);
    if ($this->isOperator($player)) return $this->allowOpAbility($ability);
    return false;
}

/**
 * Checks if the given player IP matches the corresponding list IP.
 */
function ip_match($playerip, $listip) {
    if ($playerip == '') return true;

    foreach (explode(',', $listip) as $ip) {
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip)) $match = ($playerip == $ip);
        elseif (substr($ip, -4) == '.*.*') $match = (preg_replace('/\.\d+\.\d+$/', '', $playerip) == substr($ip, 0, -4));
        elseif (substr($ip, -2) == '.*') $match = (preg_replace('/\.\d+$/', '', $playerip) == substr($ip, 0, -2));

        if ($match) return true;
    }
    return false;
}

/**
 * Checks if the given player is in the masteradmin list with an authorized IP.
 */
function isMasterAdmin($player) {
    if (isset($player->login) && $player->login != '' && isset($this->masteradmin_list['TMLOGIN'])) {
        if (($i = array_search($player->login, $this->masteradmin_list['TMLOGIN'])) !== false) {
            if ($this->masteradmin_list['IPADDRESS'][$i] != '' && !$this->ip_match($player->ip, $this->masteradmin_list['IPADDRESS'][$i])) {
                trigger_error("Attempt to use MasterAdmin login '" . $player->login . "' from IP " . $player->ip . " !", E_USER_WARNING);
                return false;
            }
            return true;
        }
    }
    return false;
}

/**
 * Checks if the given player is in the admin list with an authorized IP.
 */
function isAdmin($player) {
    if (isset($player->login) && $player->login != '' && isset($this->admin_list['TMLOGIN'])) {
        if (($i = array_search($player->login, $this->admin_list['TMLOGIN'])) !== false) {
            if ($this->admin_list['IPADDRESS'][$i] != '' && !$this->ip_match($player->ip, $this->admin_list['IPADDRESS'][$i])) {
                trigger_error("Attempt to use Admin login '" . $player->login . "' from IP " . $player->ip . " !", E_USER_WARNING);
                return false;
            }
            return true;
        }
    }
    return false;
}

/**
 * Checks if the given player is in the operator list with an authorized IP.
 */
function isOperator($player) {
    if (isset($player->login) && $player->login != '' && isset($this->operator_list['TMLOGIN'])) {
        if (($i = array_search($player->login, $this->operator_list['TMLOGIN'])) !== false) {
            if ($this->operator_list['IPADDRESS'][$i] != '' && !$this->ip_match($player->ip, $this->operator_list['IPADDRESS'][$i])) {
                trigger_error("Attempt to use Operator login '" . $player->login . "' from IP " . $player->ip . " !", E_USER_WARNING);
                return false;
            }
            return true;
        }
    }
    return false;
}

/**
 * Checks if the given player is in any admin tier with an authorized IP.
 */
function isAnyAdmin($player) {
    return ($this->isMasterAdmin($player) || $this->isAdmin($player) || $this->isOperator($player));
}

/**
 * Checks if the given player login is in the masteradmin list.
 */
function isMasterAdminL($login) {
    return isset($this->masteradmin_list['TMLOGIN']) && in_array($login, $this->masteradmin_list['TMLOGIN']);
}

/**
 * Checks if the given player login is in the admin list.
 */
function isAdminL($login) {
    return isset($this->admin_list['TMLOGIN']) && in_array($login, $this->admin_list['TMLOGIN']);
}

/**
 * Checks if the given player login is in the operator list.
 */
function isOperatorL($login) {
    return isset($this->operator_list['TMLOGIN']) && in_array($login, $this->operator_list['TMLOGIN']);
}

/**
 * Checks if the given player login is in any admin tier.
 */
function isAnyAdminL($login) {
    return ($this->isMasterAdminL($login) || $this->isAdminL($login) || $this->isOperatorL($login));
}

/**
 * Checks if the given player is a spectator.
 */
function isSpectator($player) {
    if ($this->server->getGame() != 'TMF') {
        $this->client->query('GetPlayerInfo', $player->login);
        $info = $this->client->getResponse();
        $player->isspectator = $info['IsSpectator'] ?? false;
    }
    return $player->isspectator;
}

/**
 * Handles cheating player.
 */
function processCheater($login, $checkpoints, $chkpt, $finish) {
    $cps = implode('/', array_map('formatTime', $checkpoints));

    if ($finish == -1) {
        trigger_error('Cheat by \'' . $login . '\' detected! CPs: ' . $cps . ' Last: ' . formatTime($chkpt[2]) . ' index: ' . $chkpt[4], E_USER_WARNING);
    } else {
        trigger_error('Cheat by \'' . $login . '\' detected! CPs: ' . $cps . ' Finish: ' . formatTime($finish), E_USER_WARNING);
    }

    if (!$player = $this->server->players->getPlayer($login)) {
        trigger_error('Player object for \'' . $login . '\' not found!', E_USER_WARNING);
        return;
    }

    switch ($this->settings['cheater_action']) {
        case 1:
            $rtn = $this->client->query('ForceSpectator', $login, 1);
            if (!$rtn) {
                trigger_error('[' . $this->client->getErrorCode() . '] ForceSpectator - ' . $this->client->getErrorMessage(), E_USER_WARNING);
            } else {
                $this->client->query('ForceSpectator', $login, 0);
            }
            $this->client->addCall('ForceSpectatorTarget', [$login, '', 2]);
            $this->client->addCall('SpectatorReleasePlayerSlot', [$login]);
            $this->console('Cheater [{1} : {2}] forced into free spectator!', $login, stripColors($player->nickname, false));
            $message = formatText('{#server}>> {#admin}Cheater {#highlite}{1}$z$s{#admin} forced into spectator!', str_ireplace('$w', '', $player->nickname));
            $this->client->query('ChatSendServerMessage', $this->formatColors($message));
            break;

        case 2:
            $this->console('Cheater [{1} : {2}] kicked!', $login, stripColors($player->nickname, false));
            $message = formatText('{#server}>> {#admin}Cheater {#highlite}{1}$z$s{#admin} kicked!', str_ireplace('$w', '', $player->nickname));
            $this->client->query('ChatSendServerMessage', $this->formatColors($message));
            $this->client->query('Kick', $login);
            break;

        case 3:
            $this->console('Cheater [{1} : {2}] banned!', $login, stripColors($player->nickname, false));
            $message = formatText('{#server}>> {#admin}Cheater {#highlite}{1}$z$s{#admin} banned!', str_ireplace('$w', '', $player->nickname));
            $this->client->query('ChatSendServerMessage', $this->formatColors($message));
            $this->bannedips[] = $player->ip;
            $this->writeIPs();
            $this->client->query('Ban', $player->login);
            break;

        case 4:
            $this->console('Cheater [{1} : {2}] blacklisted!', $login, stripColors($player->nickname, false));
            $message = formatText('{#server}>> {#admin}Cheater {#highlite}{1}$z$s{#admin} blacklisted!', str_ireplace('$w', '', $player->nickname));
            $this->client->query('ChatSendServerMessage', $this->formatColors($message));
            $this->client->query('BlackList', $player->login);
            $this->client->query('Kick', $player->login);
            $this->client->query('SaveBlackList', $this->settings['blacklist_file']);
            break;

        case 5:
            $this->console('Cheater [{1} : {2}] blacklisted & banned!', $login, stripColors($player->nickname, false));
            $message = formatText('{#server}>> {#admin}Cheater {#highlite}{1}$z$s{#admin} blacklisted & banned!', str_ireplace('$w', '', $player->nickname));
            $this->client->query('ChatSendServerMessage', $this->formatColors($message));
            $this->bannedips[] = $player->ip;
            $this->writeIPs();
            $this->client->query('BlackList', $player->login);
            $this->client->query('Ban', $player->login);
            $this->client->query('SaveBlackList', $this->settings['blacklist_file']);
            break;

        default:
    }
}

/**
 * Finds a player ID from its login.
 */
function getPlayerId($login, $forcequery = false) {
    if (isset($this->server->players->player_list[$login]) && $this->server->players->player_list[$login]->id > 0 && !$forcequery) {
        return $this->server->players->player_list[$login]->id;
    } else {
        $query = 'SELECT id FROM players WHERE login=' . quotedString($login);
        $result = $this->db->query($query);
        $rtn = ($result->num_rows > 0) ? $result->fetch_row()[0] : 0;
        $result->free();
        return $rtn;
    }
}

/**
 * Finds a player Nickname from its login.
 */
function getPlayerNick($login, $forcequery = false) {
    if (isset($this->server->players->player_list[$login]) && $this->server->players->player_list[$login]->nickname != '' && !$forcequery) {
        return $this->server->players->player_list[$login]->nickname;
    } else {
        $query = 'SELECT nickname FROM players WHERE login=' . quotedString($login);
        $result = $this->db->query($query);
        $rtn = ($result->num_rows > 0) ? $result->fetch_row()[0] : '';
        $result->free();
        return $rtn;
    }
}

/**
 * Finds an online player object from its login or Player_ID.
 * If $offline = true, search player database instead.
 * Returns false if not found.
 */
function getPlayerParam($player, $param, $offline = false) {
    if (is_numeric($param) && $param >= 0 && $param < 300) {
        if (empty($player->playerlist)) {
            $message = '{#server}> {#error}Use {#highlite}$i/players {#error}first (optionally {#highlite}$i/players <string>{#error})';
            $this->client->query('ChatSendServerMessageToLogin', $this->formatColors($message), $player->login);
            return false;
        }
        $pid = ltrim($param, '0') - 1;
        $param = $player->playerlist[$pid]['login'] ?? $param;
        $target = $this->server->players->getPlayer($param);
    } else {
        $target = $this->server->players->getPlayer($param);
    }

    if (!$target && $offline) {
        $query = 'SELECT * FROM players WHERE login=' . quotedString($param);
        $result = $this->db->query($query);
        if ($result->num_rows > 0) {
            $row = $result->fetch_object();
            $target = new Player();
            $target->id = $row->Id;
            $target->login = $row->Login;
            $target->nickname = $row->NickName;
            $target->nation = $row->Nation;
            $target->teamname = $row->TeamName;
            $target->wins = $row->Wins;
            $target->timeplayed = $row->TimePlayed;
        }
        $result->free();
    }

    if (!$target) {
        $message = '{#server}> {#highlite}' . $param . ' {#error}is not a valid player! Use {#highlite}$i/players {#error}to find the correct login or Player_ID.';
        $this->client->query('ChatSendServerMessageToLogin', $this->formatColors($message), $player->login);
    }
    return $target;
}

/**
 * Finds a challenge ID from its UID.
 */
function getChallengeId($uid) {
    $query = 'SELECT Id FROM challenges WHERE Uid=' . quotedString($uid);
    $res = $this->db->query($query);
    $rtn = ($res->num_rows > 0) ? $res->fetch_row()[0] : 0;
    $res->free();
    return $rtn;
}

/**
 * Gets current server name & options.
 */
function getServerOptions() {
    $this->client->query('GetServerOptions');
    $options = $this->client->getResponse();
    $this->server->name = $options['Name'];
    $this->server->maxplay = $options['CurrentMaxPlayers'];
    $this->server->maxspec = $options['CurrentMaxSpectators'];
    $this->server->votetime = $options['CurrentCallVoteTimeOut'];
    $this->server->voterate = $options['CallVoteRatio'];
}

/**
 * Formats aseco color codes in a string.
 */
function formatColors($text) {
    foreach ($this->chat_colors as $key => $value) {
        $text = str_replace('{#'.strtolower($key).'}', $value[0], $text);
    }
    return $text;
}

/**
 * Outputs a formatted string without datetime.
 */
function console_text() {
    $args = func_get_args();
    $message = call_user_func_array('formatText', $args) . CRLF;
    echo $message;
    doLog($message);
    flush();
}

/**
 * Outputs a string to console with datetime prefix.
 */
function console() {
    $args = func_get_args();
    $message = '[' . date('m/d,H:i:s') . '] ' . call_user_func_array('formatText', $args) . CRLF;
    echo $message;
    doLog($message);
    flush();
}
}
// define process settings
if (function_exists('date_default_timezone_get') && function_exists('date_default_timezone_set'))
    date_default_timezone_set(@date_default_timezone_get());

$limit = ini_get('memory_limit');
if (shorthand2bytes($limit) < 2048 * 1048576)
    ini_set('memory_limit', '2048M');

setlocale(LC_NUMERIC, 'C');

// create an instance of XASECO and run it
$aseco = new Aseco(false);
$aseco->run('config.xml');
?>
