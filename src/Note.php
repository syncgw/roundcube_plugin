<?php
declare(strict_types=1);

/*
 * 	Notes handler class
 *
 *	@package	sync*gw
 *	@subpackage	RoundCube data base
 *	@copyright	(c) 2008 - 2025 Florian Daeumling, Germany. All right reserved
 * 	@license 	LGPL-3.0-or-later
 */

namespace syncgw\interface\roundcube;

use syncgw\lib\Config;
use syncgw\lib\DB;
use syncgw\lib\DataStore;
use syncgw\lib\ErrorHandler;
use syncgw\lib\Log;
use syncgw\lib\Msg;
use syncgw\lib\Util;
use syncgw\lib\XML;
use syncgw\document\field\fldSummary;
use syncgw\document\field\fldCategories;
use syncgw\document\field\fldBody;
use syncgw\document\field\fldGroupName;
use syncgw\document\field\fldAttribute;
use syncgw\document\field\fldMessageClass;
use syncgw\document\field\fldLastMod;

class Note {

	const PLUGIN 		= [ 'ddnotes' => '1.0.2' ];

	const MAP       	= [
    // ----------------------------------------------------------------------------------------------------------------------------------------------------------
	// 	1 - Title
    //  2 - Body
    //  3 - Skip
    // ----------------------------------------------------------------------------------------------------------------------------------------------------------
		'title' 					=> [ 1, fldSummary::TAG,  		],
		'body'						=> [ 2, fldBody::TAG,	 		],
	//  'Body/Type'						// Handled by fldBody

    // some fields only included for syncDS() - not part of data record

    	'#grp_name'					=> [ 3, fldGroupName::TAG,	 	],
		'#grp_attr'					=> [ 3, fldAttribute::TAG,		],
		'#cats'						=> [ 3, fldCategories::TAG,		],
		'#msgtyp'					=> [ 3, fldMessageClass::TAG,	],
		'#lmod'						=> [ 3, fldLastMod::TAG,		],

	// ----------------------------------------------------------------------------------------------------------------------------------------------------------
	];

	// supported file type extensions
	const EXT	    	= [
	    'text/plain'    => fldBody::TYP_TXT,
	    'text/html'    	=> fldBody::TYP_HTML,
	    'text/markdown' => fldBody::TYP_MD,
	];

	// default group id
	const GRP			= DataStore::TYP_GROUP.'0';

 	/**
	 * 	Record mapping table
	 * 	@var array
	 */
	private $_ids		= null;

	/**
	 * 	Synchronization preference
	 * 	@var string
	 */
	private $_pref;

	/**
	 *  Pointer to RoundCube main handler
	 *  @var Handler
	 */
	private $_hd;

	/**
	 * 	Configuration class pointer
	 * 	@var Config
	 */
	private $_cnf;

	/**
     * 	Singleton instance of object
     * 	@var Note
     */
    static private $_obj = null;

    /**
	 *  Get class instance handler
	 *
	 *  @param  - Pointer to handler class
	 *  @return - Class object
	 */
	public static function getInstance(Handler &$hd): Note {

		if (!self::$_obj) {

			self::$_obj = new self();

			self::$_obj->_cnf = Config::getInstance();
            self::$_obj->_hd = $hd;

			// check plugin version
			foreach (self::PLUGIN as $name => $ver) {

				$a = $hd->RCube->plugins->get_info($name);
		   		if (version_compare($ver, $a['version']) < 0)
	   				return self::$_obj;
			}
		}

		return self::$_obj;
	}

    /**
	 * 	Collect information about class
	 *
	 * 	@param 	- Object to store information
 	 */
	public function getInfo(XML &$xml): void {

		$xml->addVar('Name',sprintf('RoundCube %s handler', Util::HID(Util::HID_ENAME, DataStore::NOTE)));

		// check plugin version
		foreach (self::PLUGIN as $name => $ver) {

			$i = $this->_hd->RCube->plugins->get_info($name);
			$a = $this->_hd->RCube->plugins->active_plugins;
			$xml->addVar('Opt', '<a href="https://plugins.roundcube.net/#/packages/dondominio/'.$name.'" '.
						 'target="_blank">'.$name.'</a>  plugin');
			if (!in_array($name, $a)) {

				ErrorHandler::resetReporting();
				$xml->addVar('Stat', sprintf('+++ ERROR: "%s" not active!', $name));
			} elseif ($i['version'] != 'dev-master' && version_compare($ver, $i['version']) > 0) {

				ErrorHandler::resetReporting();
				$xml->addVar('Stat', sprintf('+++ ERROR: Require plugin version "%s" - "%s" found!',
							  $ver, $i['version']));
			} else
				$xml->addVar('Stat', 'v'.$ver);
		}
	}

	/**
	 * 	Perform query on external data base
	 *
	 * 	@param	- Handler ID
	 * 	@param	- Query command:<fieldset>
	 * 			  DataStore::ADD 	  Add record                             $parm= XML object<br>
	 * 			  DataStore::UPD 	  Update record                          $parm= XML object<br>
	 * 			  DataStore::DEL	  Delete record or group (inc. sub-recs) $parm= GUID<br>
	 * 			  DataStore::RGID     Read single record       	             $parm= GUID<br>
	 * 			  DataStore::GRPS     Read all group records                 $parm= None<br>
	 * 			  DataStore::RIDS     Read all records in group              $parm= Group ID or '' for record in base group
	 * 	@return	- According  to input parameter<fieldset>
	 * 			  DataStore::ADD 	  New record ID or false on error<br>
	 * 			  DataStore::UPD 	  true=Ok; false=Error<br>
	 * 			  DataStore::DEL	  true=Ok; false=Error<br>
	 * 			  DataStore::RGID	  XML object; false=Error<br>
	 * 			  DataStore::GRPS	  [ "GUID" => Typ of record ]<br>
	 * 			  DataStore::RIDS     [ "GUID" => Typ of record ]
	 */
	public function Query(int $hid, int $cmd, $parm = '') {

		// load records?
		if (is_null( $this->_ids))
			self::_loadRecs();

		$out = true;

		switch ($cmd) {
		case DataStore::GRPS:
			// build list of records
			$out = [];
			foreach ($this->_ids as $k => $v) {

				if (substr($k, 0, 1) == DataStore::TYP_GROUP)
					$out[$k] = substr($k, 0, 1);
			}

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
				Msg::InfoMsg($out, 'All group records');
			break;

		case DataStore::RIDS:

			// build list of records
			$out = [];
			foreach ($this->_ids as $k => $v)
				if ($v[Handler::GROUP] == $parm)
					$out[$k] = substr($k, 0, 1);

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
				Msg::InfoMsg($out, 'All record ids in group "'.$parm.'"');
			break;

		case DataStore::RGID:

			if (!is_string($parm) || !self::_chkLoad($parm) || !($out = self::_swap2int($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20311, is_string($parm) ? (substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'note record' : 'notes group') : gettype($parm), $parm);
				return false;
			}
			break;

		case DataStore::ADD:

			// if we have no group, we switch to default group
			if (!($gid = $parm->getVar('extGroup')) || !isset($this->_ids[$gid])) {

				// set default group
				foreach ($this->_ids as $rid => $val) {

					if (substr($rid, 0, 1) == DataStore::TYP_GROUP && ($val[Handler::ATTR] & fldAttribute::WRITE)) {

						$gid = $rid;
				       	break;
					}
				}
			    $parm->updVar('extGroup', $gid);
			}

			// no group found?
			if ($parm->getVar('Type') == DataStore::TYP_DATA && !isset($this->_ids[$gid])) {

				Log::getInstance()->logMsg(Log::WARN, 20315, $gid, 'notes group');
				return false;
			}

			// add external record
			if (!($out = self::_add($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20312, $parm->getVar('Type') == DataStore::TYP_DATA ?
						  'note record' : 'notes group');
				return false;
			}
	   		break;

		case DataStore::UPD:

			$rid = $parm->getVar('extID');

			// be sure to check record is loaded
			if (!self::_chkLoad($rid)) {

				Log::getInstance()->logMsg(Log::WARN, 20313, substr($rid, 0, 1) == DataStore::TYP_DATA ?
						  'note record' : 'notes group', $rid);
				if ($this->_cnf->getVar(Config::DBG_LEVEL) == Config::DBG_TRACE)
					Msg::ErrMsg('Update should work - please check if synchronization is turned on!');
				return false;
			}

			// does record exist?
			if (!isset($this->_ids[$rid]) ||
				// is record editable?
			   	!($this->_ids[$rid][Handler::ATTR] & fldAttribute::EDIT)) {

				Log::getInstance()->logMsg(Log::WARN, 20315, 'notes group', $rid);
				return false;
			}

			// update external record
			if (!($out = self::_upd($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20313, substr($rid, 0, 1) == DataStore::TYP_DATA ?
						  'note record' : 'notes group', $rid);
				return false;
			}
			break;

		case DataStore::DEL:

			// be sure to check record is loaded
			if (!self::_chkLoad($parm)) {

				Log::getInstance()->logMsg(Log::WARN, 20314, substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'note record' : 'notes group', $parm);
				return false;
			}

			// does record exist?
			if (!isset($this->_ids[$parm])) {
				Log::getInstance()->logMsg(Log::WARN, 20315, $parm, 'notes group');
				return false;
			}

			// delete  external record
			if (!($out = self::_del($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20314, substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'note record' : 'notes group', $parm);
				return false;
			}
			break;

		default:
			break;
		}

		return $out;
	}

	/**
	 * 	Get list of supported fields in external data base
	 *
	 * 	@param	- Handler ID
	 * 	@return	- [ field name]
	 */
	public function getflds(int $hid): array {

		$rc = [];
		foreach (self::MAP as $k => $v)
			if ($v[0] != 3)
			$rc[] = $v[1];
		$k; // disable Eclipse warning

		return $rc;
	}

	/**
	 * 	Reload any cached record information in external data base
	 *
	 * 	@param	- Handler ID
	 * 	@return	- true=Ok; false=Error
	 */
	public function Refresh(int $hid): bool {

	    self::_loadRecs();

	    return true;
	}

	/**
	 * 	Check trace record references
	 *
	 *	@param 	- Handler ID
	 * 	@param 	- External record array [ GUID ]
	 * 	@param 	- Mapping table [HID => [ GUID => NewGUID ] ]
	 */
	public function chkTrcReferences(int $hid, array $rids, array $maps): void {
	}

	/**
	 * 	(Re-) load existing external records
	 *
	 *  @param 	- null= root; else <GID> to load
 	 */
	private function _loadRecs(?string $grp = null): void {

		// get synchronization preferences
        $p = $this->_hd->RCube->user->get_prefs();
        $this->_pref = isset($p['syncgw']) ? $p['syncgw'] : '';
        Msg::InfoMsg('Folder to synchronize "'.$this->_pref.'"');

		// re-create list
		if (!$grp) {

			// no notes available
        	$this->_ids = [];
			$this->_ids[self::GRP] = [
						Handler::GROUP => '',
				        Handler::NAME  => 'Notes default group',
						Handler::LOAD  => 1,
						Handler::ATTR  => fldAttribute::READ|fldAttribute::WRITE|fldAttribute::DEFAULT,
     		];

	    	// included in synchronization?
   			if (strpos($this->_pref, Handler::NOTES_FULL.'0'.';') === false) {

	    		Log::getInstance()->logMsg(Log::ERR, 20350, 'notes group', $this->_pref);
	    		$this->_ids = [];
	   			return;
   			}
		}

		$sql = 'SELECT * FROM `'.$this->_hd->RCube->config->get('db_table_lists', $this->_hd->RCube->db->table_name('ddnotes')).'`'.
			   ' WHERE user_id = ?';
		do {

	        $res = $this->_hd->RCube->db->query($sql, $this->_hd->RCube->user->ID);
		} while ($this->_hd->chkRetry(DataStore::NOTE, __LINE__));

	    if (!$this->_hd->Retry)
			return;

		$recs = [];
		do {

			while ($rec = $this->_hd->RCube->db->fetch_assoc($res))
				$recs[] = $rec;
		} while ($this->_hd->chkRetry(DataStore::NOTE, __LINE__));

		foreach ($recs as $rec)
			// supported?
			if (isset(self::EXT[$rec['mimetype']]))
				$this->_ids[DataStore::TYP_DATA.$rec['id']] = [
						Handler::GROUP => self::GRP,
				        Handler::NAME  => $rec['title'],
						Handler::ATTR  => fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
	     		];

    	if ($this->_cnf->getVar(Config::DBG_SCRIPT) == 'DBExt') {

        	$ids = $this->_ids;
        	foreach ($this->_ids as $id => $unused)
        		$ids[$id][Handler::ATTR] = fldAttribute::showAttr($ids[$id][Handler::ATTR]);
        	$unused; // disable Eclipse warning
        	Msg::InfoMsg($ids, 'Record mapping table ('.count($this->_ids).')');
    	}
	}

	/**
	 * 	Check record is loadeded
	 *
	 *  @param 	- Record id to load
	 *  @return - true=Ok; false=Error
 	 */
	private function _chkLoad(string $rid): bool {

		// any GUID given?
	    if (!$rid)
	    	return false;

	    // alreay loaded?
		if (!isset($this->_ids[$rid])) {

			foreach ($this->_ids as $id => $parm) {

				if (substr($id, 0, 1) == DataStore::TYP_GROUP && !$parm[Handler::LOAD]) {

					// load group
					self::_loadRecs($id);

					// could we load record?
					if (isset($this->_ids[$rid]))
						return true;
				}
			}
			return false;
		}

		return true;
	}

	/**
	 * 	Get external record
	 *
	 *	@param	- External record ID
	 * 	@return - Internal document or null
	 */
	private function _swap2int(string $rid): ?XML {

		$db = DB::getInstance();

		if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			$int = $db->mkDoc(DataStore::NOTE, [
						'Group' 			=> '',
						'Typ'   			=> DataStore::TYP_GROUP,
						'extID'				=> $rid,
						fldGroupName::TAG	=> $this->_ids[$rid][Handler::NAME],
						fldAttribute::TAG	=> $this->_ids[$rid][Handler::ATTR],
			]);

		} else {

			// load external record
			if (!($rec = self::_get($rid)))
				return null;

			// create XML object
			$int = $db->mkDoc(DataStore::NOTE, [
							'GID' 		=> '',
							'extID'		=> $rid,
							'extGroup'	=> self::GRP,
			]);

			foreach (self::MAP as $unused => $tag) {

				switch ($tag[0]) {
				// 	1 - Title
			    case 1:
			    	if ($rec['title'])
						$int->addVar($tag[1], $rec['title']);
					break;

				//  2 - Body
				case 2:
					$int->addVar($tag[1], $rec['body'], false, [ 'X-TYP' =>
									self::EXT[ $rec['typ'] ? $rec['typ'] : 'text/plain' ] ]);

				// 3 - Skip
				case 3:
					break;
				}
			}
			$unused; // disable Eclipse warning
		}

		// add missing field
		$int->addVar(fldMessageClass::TAG, 'IPM.StickyNote');

		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

			$int->getVar('syncgw');
            Msg::InfoMsg($int, 'Internal record');
		}

		return $int;
	}

	/**
	 * 	Swap internal to external record
	 *
	 *	@param 	- Internal document
	 * 	@return - External document
	 */
	private function _swap2ext(XML &$int): array {

		$rid = $int->getVar('extID');

		// output record
		$rec  = [
					'id' 		=> substr($rid, 1),
					'title'		=> null,
					'body'		=> null,
					'typ'		=> null,
		];

		$int->getVar('Data');
		$map = array_flip(self::EXT);

		// swap data
		foreach (self::MAP as $key => $tag) {

			$ip = $int->savePos();

			switch ($tag[0]) {
			// 	1 - Title
		    case 1:
				if ($val = $int->getVar($tag[1], false))
					$rec[$key] = $val;
				break;

			//  2 - Body
			case 2:
				if ($val = $int->getVar($tag[1], false)) {
					$rec[$key] = $val;
					if ($t = $int->getAttr('X-TYP'))
						$rec['typ'] = $map[ $t ];
					else
						$rec['typ'] = fldBody::TYP_TXT;
				}

			// 3 - Skip
			case 3:
				break;
			}

			$int->restorePos($ip);
		}

		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
	        Msg::InfoMsg($rec, 'External record');

		return $rec;
	}

	/**
	 *  Get record
	 *
	 *  @param  - Record Id
	 *  @return - External record or null on error
	 */
	private function _get(string $rid): ?array {

	    $sql    = 'SELECT * FROM `'.$this->_hd->RCube->config->get('db_table_lists',
	    				$this->_hd->RCube->db->table_name('ddnotes')).'`'.
			      ' WHERE id = ?';
	    do {

	    	if ($res = $this->_hd->RCube->db->query($sql, substr($rid, 1)))
	    		$r = $this->_hd->RCube->db->fetch_assoc($res);
		} while ($this->_hd->chkRetry(DataStore::NOTE, __LINE__));

   		if (!$this->_hd->Retry)
			return null;

		// output record
		$rec = [
					'id' 		=> substr($rid, 1),
					'title'		=> $r['title'],
					'body'		=> $r['content'] == 'null' ? '' : $r['content'],
					'typ'		=> $r['mimetype'],
		];

		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
            Msg::InfoMsg($rec, 'External record');

		return $rec;
	}

	/**
	 *  Add external record
	 *
	 *  @param  - XML record
	 *  @return - New record Id or null on error
	 */
	private function _add(XML &$int): ?string {

		// check for group
		if ($int->getVar('Type') == DataStore::TYP_GROUP)
			return null;

		// create external record
		$rec = self::_swap2ext($int);

	    $sql = 'INSERT INTO `'.$this->_hd->RCube->config->get('db_table_lists', $this->_hd->RCube->db->table_name('ddnotes')).'`'.
			   ' SET `user_id` = %s, `parent_id` = %d, `title` = "%s", '.
			   '     `mimetype` = "%s", `content`  = "%s", `file_size`  = %d';
		$sql = sprintf($sql, $this->_hd->RCube->user->ID, 0, $this->_hd->RCube->db->escape($rec['title'] ? $rec['title'] : ''), $rec['typ'],
        					$this->_hd->RCube->db->escape($rec['body']), strlen(strval($rec['body'])));
		do {

			if ($this->_hd->RCube->db->query($sql))
				$rid = $this->_hd->RCube->db->insert_id();
		} while ($this->_hd->chkRetry(DataStore::NOTE, __LINE__));

   		if (!$this->_hd->Retry)
    		return null;

		// add records to known list
		$this->_ids[$rid = DataStore::TYP_DATA.$rid] = [
				Handler::GROUP 	=> self::GRP,
				Handler::NAME  	=> $rec['title'],
				Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
		];

		$id = $this->_ids[$rid];
        $id[Handler::ATTR] = fldAttribute::showAttr($id[Handler::ATTR]);
        if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
			Msg::InfoMsg($id, 'New mapping record "'.$rid.'" ('.count($this->_ids).')');

		return $rid;
	}

	/**
	 *  Update external record
	 *
	 *  @param  - XML record
	 *  @param	- External record
	 *  @return - true or false on error
	 */
	private function _upd(XML &$int): bool {

		// get external record id
		$rid = $int->getVar('extID');

		// create external record
		$rec = self::_swap2ext($int);

	    $sql = 'UPDATE `'.$this->_hd->RCube->config->get('db_table_lists', $this->_hd->RCube->db->table_name('ddnotes')).'`'.
			   ' SET ``title` = "%s", `mimetype` = "%s", `content`  = "%s", `file_size`  = %d, `ts_updated` = "%s" '.
			   ' WHERE `id` = %d AND `user_id`= %d';
		$sql = sprintf($sql, $this->_hd->RCube->db->escape($rec['title']), $rec['typ'], $this->_hd->RCube->db->escape($rec['body']),
					   strlen($rec['body']), date("Y-m-d H:i:s"), substr($rid, 1), $this->_hd->RCube->user->ID);
		do {
			$this->_hd->RCube->db->query($sql);
		} while ($this->_hd->chkRetry(DataStore::NOTE, __LINE__));

   		if (!$this->_hd->Retry)
			return null;

		// add record to internal managament
		$this->_ids[$rid] = [
				Handler::GROUP => self::GRP,
				Handler::NAME  => $rec['title'],
		];

		return true;
	}

	/**
	 * 	Delete external record
	 *
	 * 	@param 	- Record id
	 * 	@return - true=Ok, false=Error
	 */
	private function _del(string $rid): bool {

		$ids = [];

		// we ignore deletion of address books
		if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			// but we will delete content of group!
			foreach ($this->_ids as $id => $v)
				if ($v[Handler::GROUP] == $rid)
					$ids[] = $id;

		} else
			$ids[] = $rid;

		// perform deletion

		foreach ($ids as $id) {

		    $sql = 'DELETE FROM `'.$this->_hd->RCube->config->get('db_table_lists', $this->_hd->RCube->db->table_name('ddnotes')).'`'.
				   ' WHERE `id` = %d AND `user_id`= %d';
			$sql = sprintf($sql, substr($id, 1), $this->_hd->RCube->user->ID);
			do {
				$this->_hd->RCube->db->query($sql);
			} while ($this->_hd->chkRetry(DataStore::NOTE, __LINE__));

   			if (!$this->_hd->Retry)
    			return false;

    		unset($this->_ids[$id]);
		}

		return true;
	}

}
