<?php
declare(strict_types=1);

/*
 * 	Administration interface handler class
 *
 *	@package	sync*gw
 *	@subpackage	RoundCube data base
 *	@copyright	(c) 2008 - 2024 Florian Daeumling, Germany. All right reserved
 * 	@license 	LGPL-3.0-or-later
 */

namespace syncgw\interface\roundcube;

use syncgw\interface\DBAdmin;
use rcube_db;
use syncgw\lib\Config;
use syncgw\lib\DataStore;
use syncgw\lib\ErrorHandler;
use syncgw\gui\guiHandler;

class Admin extends \syncgw\interface\mysql\Admin implements DBAdmin {

    /**
     * 	Singleton instance of object
     * 	@var Admin
     */
    static private $_obj = null;

    /**
	 *  Get class instance handler
	 *
	 *  @return - Class object
	 */
	public static function getInstance(): Admin {

	   	if (!self::$_obj)
            self::$_obj = new self();

		return self::$_obj;
	}

	/**
	 * 	Show/get installation parameter
	 */
	public function getParms(): void {

		$gui = guiHandler::getInstance();
		$cnf = Config::getInstance();

		if(!($c = $gui->getVar('rcPref')))
			$c = $cnf->getVar(Config::DB_PREF);
		$gui->putQBox('MySQL <strong>sync&bull;gw</strong> data base table name prefix',
					'<input name="rcPref" type="text" size="20" maxlength="40" value="'.$c.'" />',
					'Table name prefix for <strong>sync&bull;gw</strong> data base tables '.
					'(to avaoid duplicate table names in data base).', false);
	}

    /**
	 * 	Connect to handler
	 *
	 * 	@return - true=Ok; false=Error
	*/
	public function Connect(): bool {

		$gui = guiHandler::getInstance();
		$cnf = Config::getInstance();

		// already connected?
		if ($cnf->getVar(Config::DATABASE))
			return true;

		// get application root directory
		$path = '..';
		// check for hidden parameter
		if ($c = $cnf->getVar(Config::RC_DIR))
			$path = $c;
		$path = realpath($path);

		if (!file_exists($file = $path.'/config/config.inc.php')) {

			$gui->clearAjax();
			$gui->putMsg(sprintf('Error loading required RoundCube configuration file \'%s\'', $file), Config::CSS_ERR);
			return false;
		}
		$cnf->updVar(Config::RC_DIR, $path);

		// set error filter
		ErrorHandler::filter(E_NOTICE|E_WARNING, $path);

		$config = []; // disable Eclipse warning

		// load RoundCube configuration
		require_once ($file);

		// load and save data base configuration
		require_once ($path.'/program/lib/Roundcube/rcube_db.php');

		// variable $config is defined in rcube_db.php
		$conf = rcube_db::parse_dsn($config['db_dsnw']);
		ErrorHandler::resetReporting();

		if ($conf['dbsyntax'] != 'mysql') {
			$gui->clearAjax();
			$gui->putMsg(sprintf('We do not support \'%s\' connection', $conf['dbsyntax']), Config::CSS_ERR);
			return false;
		}

		$cnf->updVar(Config::DB_HOST, $conf['hostspec']);
		$cnf->updVar(Config::DB_PORT, '3306');
		$cnf->updVar(Config::DB_USR, $conf['username']);
		$cnf->updVar(Config::DB_UPW, $conf['password']);
		$cnf->updVar(Config::DB_NAME, $conf['database']);
		$cnf->updVar(Config::DB_PREF, $gui->getVar('rcPref'));

		// create tables
		return parent::mkTable();
	}

	/**
	 * 	Disconnect from handler
	 *
	 * 	@return - true=Ok; false=Error
	 */
	public function DisConnect(): bool {

		return parent::delTable();
	}

	/**
	 * 	Return list of supported data store handler
	 *
	 * 	@return - Bit map of supported data store handler
	 */
	public function SupportedHandlers(): int {

		return DataStore::EXT|DataStore::CONTACT|DataStore::CALENDAR|DataStore::TASK|DataStore::NOTE;
	}

}
