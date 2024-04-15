<?php
declare(strict_types=1);

/*
 * 	Task handler class
 *
 *	@package	sync*gw
 *	@subpackage	RoundCube data base
 *	@copyright	(c) 2008 - 2024 Florian Daeumling, Germany. All right reserved
 * 	@license 	LGPL-3.0-or-later
 */

namespace syncgw\interface\roundcube;

use syncgw\lib\Config;
use syncgw\lib\DB;
use syncgw\lib\DataStore;
use syncgw\lib\Encoding;
use syncgw\lib\ErrorHandler;
use syncgw\lib\Log;
use syncgw\lib\Msg;
use syncgw\lib\Trace;
use syncgw\lib\Util;
use syncgw\lib\XML;
use libcalendaring;
use syncgw\document\field\fldRelated;
use syncgw\document\field\fldSummary;
use syncgw\document\field\fldBody;
use syncgw\document\field\fldDueDate;
use syncgw\document\field\fldStartTime;
use syncgw\document\field\fldFlag;
use syncgw\document\field\fldStatus;
use syncgw\document\field\fldAlarm;
use syncgw\document\field\fldRecurrence;
use syncgw\document\field\fldTrigger;
use syncgw\document\field\fldGroupName;
use syncgw\document\field\fldAttribute;
use syncgw\document\field\fldUid;
use syncgw\document\field\fldCompleted;

class Task {

	const MAP   		= [
	// ----------------------------------------------------------------------------------------------------------------------------------------------------------
	// fld definitions tasklist/drivers/tasklist_driver.php
    // 	0 - String
    // 	1 - Category array
    //  2 - Date
    //  3 - Recurrence array
   	//  4 - Attendee and Organizer array
    //  5 - VALARM
    //  6 - Related to
   	//  7 - Skip
	// ----------------------------------------------------------------------------------------------------------------------------------------------------------
	// 	'id'																// Task ID used for editing
		'parent_id'					=> [ 6, fldRelated::TAG, 			],	// ID of parent task - not used in MAS
		'uid'						=> [ 0, fldUid::TAG,				],	// Unique identifier of this task
	//	'list'																// Task list identifier to add the task to or where the task is stored
    //	'changed'															// Last modification date/time of the record
    	'title'						=> [ 0, fldSummary::TAG, 			],	// Event title/summary
    	'description'               => [ 0, fldBody::TAG, 				],	// Event description
	//  'tags'		                										// List of tags for this task
    	'date'						=> [ 2, fldDueDate::TAG, 			],	// Due date
	//	'time'																// Due time
    	'startdate'					=> [ 2, fldStartTime::TAG,			],	// Start date
	//	'starttime'															// Start time
	//	'categories'														// Task category
		'flagged'					=> [ 0, fldFlag::TAG,				],	// Boolean value whether this record is flagged - not used by MAS
	//	'complete'                 											// Float value representing the completeness state (see fldStatus::TAG)
	    'status'                    => [ 0, fldStatus::TAG,				],	// Task status string according to (NEEDS-ACTION, IN-PROCESS, COMPLETED, CANCELLED)
	    																	// Attribute: X-PC= Value representing the completeness state
		'valarms'                   => [ 5, fldAlarm::TAG, 				],	// List of reminders
	    'recurrence'                => [ 3, fldRecurrence::TAG, 		],	// Recurrence definition according to iCalendar (RFC 2445)
    //	'_fromlist'															// List identifier where the task was stored before

	// 	'del'																// Processed
    //  'notify'															// ignored
	//	'organizer'                 										// field in data base, but ignored by front end
    //	'attendees'										                 	// field in data base, but ignored by front end

    // some fields only included for syncDS() - not part of data record

    	'#trigger'					=> [ 7, fldTrigger::TAG,			],
		'#grp_name'					=> [ 7, fldGroupName::TAG,	 		],
		'#grp_attr'					=> [ 7, fldAttribute::TAG,			],
		'#date_comp'				=> [ 7, fldCompleted::TAG,			],

    // ----------------------------------------------------------------------------------------------------------------------------------------------------------
	];

	// roundcube table names
	const LISTS 		= 0;
	const TASKS 		= 1;

    const PLUGIN 		= [ 'tasklist' => '3.5.10', 'libcalendaring' => '3.5.11' ];

	/**
	 *  RoundCube database table names
	 *  @var array
	 */
	private $_tab;

	/**
	 * 	Record mapping table
	 * 	@var array
	 */
	private $_ids = null;

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
     *  Internal default group id
     *  @var string
     */
	private $_gid;

	/**
	 * 	Configuration class pointer
	 * 	@var Config
	 */
	private $_cnf;

	/**
     * 	Singleton instance of object
     * 	@var Task
     */
    static private $_obj = null;

    /**
	 *  Get class instance handler
	 *
	 *  @param  - Pointer to handler class
	 *  @return - Class object
	 */
	public static function getInstance(Handler &$hd): Task {

		if (!self::$_obj) {

            self::$_obj = new self();
			self::$_obj->_cnf = Config::getInstance();

			self::$_obj->_hd = $hd;

			// tasklist_database_driver.php:__construct()
			$db = $hd->RCube->get_dbh();
	        self::$_obj->_tab[self::LISTS] = '`'.$hd->RCube->config->get('db_table_lists', $db->table_name('tasklists')).'`';
	        self::$_obj->_tab[self::TASKS] = '`'.$hd->RCube->config->get('db_table_tasks', $db->table_name('tasks')).'`';

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

		$xml->addVar('Name',sprintf('RoundCube %s handler', Util::HID(Util::HID_ENAME, DataStore::TASK)));

		// check plugin version
		foreach (self::PLUGIN as $name => $ver) {

			$i = $this->_hd->RCube->plugins->get_info($name);
			$a = $this->_hd->RCube->plugins->active_plugins;
			$xml->addVar('Opt', '<a href="https://plugins.roundcube.net/#/packages/kolab/'.$name.'" target="_blank">'.$name.'</a> '.
					      ' plugin');
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

		$xml->addVar('Opt', 'Checking \'tasklist\' data base layout');
		$dir = $this->_cnf->getVar(Config::RC_DIR).'/plugins/tasklist/drivers/database/SQL/mysql';

		if (!file_exists($dir) || !is_dir($dir) || !($h = opendir($dir)))
    		$xml->addVar('Stat', sprintf('+++ ERROR: Cannot find \'%s\'!', $dir));

		$ver = '';
		while($file = readdir($h)) {

			if ($file != '.' && $file != '..' && $file > $ver)
			    $ver = $file;
		}
		closedir($h);

		if ($ver != ($v = '2021102600.sql'))
    		$xml->addVar('Stat', sprintf('+++ ERROR: Should be \'%s\' - is \'%s\'!', $v, $ver));
   		else
   			$xml->addVar('Stat', 'Passed');
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
		if (is_null($this->_ids))
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

			// find base group?
			if ($parm == '') {
				foreach ($this->_ids as $rid => $val) {

					if (!$val[Handler::GROUP]) {

						$parm = $rid;
						break;
					}
				}
			}

			// check group (never should go here)
			if (!isset($this->_ids[$parm])) {

				Log::getInstance()->logMsg(Log::WARN, 20317, $parm, 'task list');
				return false;
			}

			// late load group?
			if (substr($parm, 0, 1) == DataStore::TYP_GROUP && !$this->_ids[$parm][Handler::LOAD])
				self::_loadRecs($parm);

			// build list of records
			$out = [];
			foreach ($this->_ids as $k => $v) {

				if ($v[Handler::GROUP] == $parm)
					$out[$k] = substr($k, 0, 1);
			}

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
				Msg::InfoMsg($out, 'All record ids in task list "'.$parm.'"');
			break;

		case DataStore::RGID:

			if (!is_string($parm) || !self::_chkLoad($parm) || !($out = self::_swap2int($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20311, is_string($parm) ? (substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'task record' : 'task list') : gettype($parm), $parm);
				return false;
			}
			break;

		case DataStore::ADD:

			// adding default group?
			if ($parm->getVar(fldAttribute::TAG) & fldAttribute::DEFAULT) {

				// not possible, so we fake success
				$out = false;
				foreach ($this->_ids as $gid => $t)
					if ($t[Handler::ATTR] & fldAttribute::DEFAULT) {

						$out = $gid;
						break;
					}

				// update defalt group
				if ($out) {

					$parm->updVar('extID', $out);
					self::_upd($parm);
				}
				break;
			}

			// if we have no group, we switch to default group
			if (!($gid = $parm->getVar('extGroup')) || !isset($this->_ids[$gid])) {

				// set default group
				foreach ($this->_ids as $rid => $val) {

					if (substr($rid, 0, 1) == DataStore::TYP_GROUP && ($val[Handler::ATTR] & fldAttribute::EDIT)) {

						$gid = $rid;
				       	break;
					}
				}
			    $parm->updVar('extGroup', $gid);
			}

			// no group found?
			if ($parm->getVar('Type') == DataStore::TYP_DATA && !isset($this->_ids[$gid])) {

				Log::getInstance()->logMsg(Log::WARN, 20317, $gid, 'task list');
				return false;
			}

			// add external record
			if ($parm->getVar('Type') == DataStore::TYP_DATA && !isset($this->_ids[$gid])) {

				Log::getInstance()->logMsg(Log::WARN, 20312, $parm->getVar('Type') == DataStore::TYP_DATA ?
						  'task record' : 'task list');
				return false;
			}

			// add external record
			if (!($out = self::_add($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20312, $parm->getVar('Type') == DataStore::TYP_DATA ?
						  'task record' : 'task list');
				return false;
			}
			break;

		case DataStore::UPD:

			$rid = $parm->getVar('extID');

			// be sure to check record is loaded
			if (!self::_chkLoad($rid)) {

				Log::getInstance()->logMsg(Log::WARN, 20313, substr($rid, 0, 1) == DataStore::TYP_DATA ?
						  'task record' : 'task list', $rid);
				if ($this->_cnf->getVar(Config::DBG_LEVEL) == Config::DBG_TRACE)
					Msg::ErrMsg('Update should work - please check if synchronization is turned on!');
				return false;
			}

			// get group ID
			$gid = substr($rid, 0, 1) == DataStore::TYP_GROUP ? $rid : $this->_ids[$rid][Handler::GROUP];
			// does record exist?
			if (!isset($this->_ids[$rid]) ||
				// is record editable?
			   	!($this->_ids[$rid][Handler::ATTR] & fldAttribute::EDIT) ||
				// is group writable?^
				!($this->_ids[$gid][Handler::ATTR] & fldAttribute::WRITE)) {

				Log::getInstance()->logMsg(Log::WARN, 20317, $rid, 'task list');
				return false;
			}

			// update external record
			if (!($out = self::_upd($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20313, substr($rid, 0, 1) == DataStore::TYP_DATA ?
						  'task record' : 'task list', $rid);
				return false;
			}
    		break;

       	case DataStore::DEL:

			// be sure to check record is loaded
			if (!self::_chkLoad($parm)) {

				Log::getInstance()->logMsg(Log::WARN, 20314, substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'task record' : 'task list', $parm);
				return false;
			}

			// does record exist?
			if (!isset($this->_ids[$parm]) ||
				// is record a group and is it allowed to delete?
			    (substr($parm, 0, 1) == DataStore::TYP_GROUP && !($this->_ids[$parm][Handler::ATTR] & fldAttribute::DEL) ||
				// is record a data records and is it allowed to delete?
			   	(substr($parm, 0, 1) == DataStore::TYP_DATA &&
			   	!($this->_ids[$this->_ids[$parm][Handler::GROUP]][Handler::ATTR] & fldAttribute::WRITE)))) {

			   	Log::getInstance()->logMsg(Log::WARN, 20317, $parm, 'task list');
				return false;
			}

			// delete  external record
			if (!($out = self::_del($parm))) {

				Log::getInstance()->logMsg(Log::WARN, 20314, substr($parm, 0, 1) == DataStore::TYP_DATA ?
						  'task record' : 'task list', $parm);
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
	 * 	@return	- [ field name ]
	 */
	public function getflds(int $hid): array {

		$rc = [];
		foreach (self::MAP as $k => $v)
			if ($v[0] != 7)
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

		foreach ($rids as $rid) {

			// get record
			$rec = self::_get($rid);

			// check for reference
			if (!isset($rec['parent_id']))
				continue;

			$ngid = isset($maps[$hid][DataStore::TYP_DATA.$rec['parent_id']]) ?
					$maps[$hid][DataStore::TYP_DATA.$rec['parent_id']] : 0;

			// do we need to change reference?
			if (is_string($ngid)) {

		        $sql = sprintf('UPDATE '.$this->_tab[self::TASKS].
        		               ' SET parent_id = ?'.
                    		   ' WHERE task_id = ? AND tasklist_id = ?',
                        		$this->_hd->RCube->db->now());
   				if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
					Msg::InfoMsg($sql);

				do {

					$this->_hd->RCube->db->query($sql, substr($ngid, 1), $rec['id'], $rec['list']);
				} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

				Msg::InfoMsg('['.$rid.'] Updating reference field [parent_id] from ['.
						   DataStore::TYP_DATA.$rec['parent_id'].'] to ['.$ngid.']');
			}
		}
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

		// tasklist_database_driver.php:_read_lists()
        $h = array_filter(explode(',', $this->_hd->RCube->config->get('hidden_tasklists', '')));

		// re-create list
		if (!$grp) {

    		// no task entries available
	        $this->_ids = [];

	       	$sql = 'SELECT * FROM '.$this->_tab[self::LISTS].' WHERE user_id = '.$this->_hd->RCube->user->ID;
	        if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
	        	Msg::InfoMsg($sql);

	        do {

	        	$res = $this->_hd->RCube->db->query($sql);
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

			$recs = [];
			do {

				while ($rec = $this->_hd->RCube->db->fetch_assoc($res))
					$recs[] = $rec;
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

 			// data structure: plugins/tasklist/drivers/taslöist_driver.php

			foreach ($recs as $rec) {

				// included in synchronization?
				if (strpos($this->_pref, Handler::TASK_FULL.$rec['tasklist_id'].';') === false)
	    			continue;

	        	$rec['showalarms'] = intval($rec['showalarms']);
	            $rec['active']     = !in_array($rec['tasklist_id'], $h);
	            $rec['name']       = XML::cnvStr($rec['name']);
	            $rec['listname']   = XML::cnvStr($rec['name']);
	            $rec['editable']   = 1;
	            $rec['rights']     = 'lrswikxtea';

				// enabled?
	    		if (!$rec['active'])
		       		continue;

				// swap
				$this->_ids[$rid = DataStore::TYP_GROUP.$rec['tasklist_id']] = [
						Handler::GROUP  => '',
            			Handler::NAME  	=> $rec['name'],
		 	       		Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE,
						Handler::LOAD	=> 0,
				];
		       	if ($rec['editable'])
		       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::EDIT;
		       	if ($rec['showalarms'])
		       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::ALARM;

		       	// we assume first group is default group
		       	if (count($this->_ids) == 1)
		       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::DEFAULT;
	       		else
			       	$this->_ids[$rid][Handler::ATTR] |= fldAttribute::DEL;
			}
		} else {

			$f = 0;
    		$this->_ids[$grp][Handler::LOAD] = 1;

            // tasklist_database_driver.php:list_tasks()
            $sql = 'SELECT task_id, del FROM '.$this->_tab[self::TASKS].
                   ' WHERE tasklist_id = ? AND del = ?';
            if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
            	Msg::InfoMsg($sql);

            // only load non-deleted
            do {
            	$res = $this->_hd->RCube->db->query($sql, substr($grp, 1), 0);
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

			$recs = [];
			do {

				while ($rec = $this->_hd->RCube->db->fetch_assoc($res))
					$recs[] = $rec;
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

			foreach ($recs as $rec) {

            	if (!$rec['del']) {

    				$this->_ids[DataStore::TYP_DATA.$rec['task_id']] = [
    						Handler::GROUP 	=> $grp,
    						Handler::CID	=> '',
							Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
    				];
    				// found flag
           			$f = 1;
                }
	       	}
    		if (!$f && $this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
	       		Msg::InfoMsg('No records found in task list "'.$grp.'"');
       	}

    	if (!count($this->_ids))
    		Log::getInstance()->logMsg(Log::ERR, 20350, 'task list', $this->_pref);

    	if ($this->_cnf->getVar(Config::DBG_SCRIPT) == 'DBExt') {

        	$ids = $this->_ids;
        	foreach ($this->_ids as $id => $unused)
        		$ids[$id][Handler::ATTR] = fldAttribute::showAttr($ids[$id][Handler::ATTR]);
        	$unused; //disable Eclipse warning
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

			$int = $db->mkDoc(DataStore::TASK, [
						'GID'   			 => '',
						'Typ'   			 => DataStore::TYP_GROUP,
						'extID'				 => $rid,
						'extGroup'			 => $this->_ids[$rid][Handler::GROUP],
						fldGroupName::TAG	 => $this->_ids[$rid][Handler::NAME],
						fldAttribute::TAG	 => $this->_ids[$rid][Handler::ATTR],
			]);

			if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

				$int->updVar(fldAttribute::TAG, fldAttribute::showAttr($this->_ids[$rid][Handler::ATTR]));
				$int->getVar('syncgw');
	            Msg::InfoMsg($int, 'Internal record');
	            $int->updVar(fldAttribute::TAG, strval($this->_ids[$rid][Handler::ATTR]));
			}

			return $int;
		}

		// load external record
		if (!($rec = self::_get($rid)))
			return null;

		// $att = Attachment::getInstance();
		$int = $db->mkDoc(DataStore::TASK, [
					'GID' 		=> '',
					'extID'		=> $rid,
					'extGroup'	=> $this->_ids[$rid][Handler::GROUP],
		]);

      	// swap data
		foreach (self::MAP as $key => $tag) {

			// empty field?
			if (!isset($rec[$key]))
			    continue;

            if (!intval($val = $rec[$key]) && (is_string($val) && !strlen($val)))
				continue;

		    switch ($tag[0]) {
			// 	0 - String
		    case 0:
		    	if ($tag[1] == fldBody::TAG)
					$int->addVar($tag[1], $val, false, [ 'X-TYP' => fldBody::TYP_TXT ]);
				else {

					if ($key == 'status')
               			$int->addVar($tag[1], $val, false, [ 'X-PC' => floatval($rec['complete']) * 100. ]);
					else
			    	    $int->addVar($tag[1], $val);
				}
				break;

			// 	1 - Category array
		    // case 1:
			//	foreach ($val as $val)
			//		$int->addVar($tag[1], $val);
			//	break;

			//  2 - Date
		    case 2:
				// due to a bug in plugin implementation, we ignore RoundCube time zone setting
				if ($key == 'date')
					$val .= 'T'.$rec['time'];
				else
					$val .= 'T'.$rec['starttime'];
				$int->addVar($tag[1], strval(Util::unxTime($val, 'UTC')));
				break;

			//  3 - Recurrence array
		   	case 3:
		   	    if (!count($val))
		   	        break;
				$v = '';
				$ip = $int->savePos();
				$int->addVar($tag[1]);
				foreach ($val as $k => $v) {

					if ($v instanceof \DateTime) {

						$v->setTimezone(new \DateTimeZone('UTC'));
						$v = $v->format(Config::UTC_TIME);
					}
					if (!isset(fldRecurrence::RFC_SUB[$k])) {

			    		if ($k != 'EXCEPTIONS')
							$this->_msg->WarnMsg('+++ Undefined sub field ['.$k.']');
			    		continue;
   		    		}
   		    		// check for regeneration
   		    		if ($k == 'FREQ') {

   		    			$reg = '0';
   		    			foreach (fldRecurrence::AS_FREQ as $unused => $chk)
   		    				if ($chk == $v) {

   		    					$reg = '1';
   		    					break;
   		    				}
   		    			$unused; // disable Eclipse warning
	   		    		$int->addVar(fldRecurrence::AST_SUB['Regenerate'][2], $reg);
   		    		} elseif ($k == 'UNTIL')
   		    			$v = Util::unxTime($v);
   		    		elseif ($k == 'X-START')
   		    			$v = Util::unxTime($rec['date'].'T'.$rec['time'], 'UTC');
   		    		$int->addVar(fldRecurrence::RFC_SUB[$k], strval($v));
    		    }
    		    // add missing start time
    		    $p = $int->savePos();
    		    if (!($v = $int->getVar(fldStartTime::TAG)))
    		    	$v = $int->getVar(fldDueDate::TAG);
    		    $int->restorePos($p);
    		    $int->addVar(fldStartTime::TAG, $v);
    		    $int->restorePos($ip);
				break;

		    //  5 - VALARM
		    case 5:
				$ip = $int->savePos();
	            foreach ($val as $r) {
	            	$p = $int->savePos();
					$int->addVar($tag[1]);
	            	$int->addVar(fldAlarm::SUB_TAG['VCALENDAR/%s/VALARM/ACTION'], $r['action']);
	            	if ($r['trigger'] instanceof \DateTime)
		            	$int->addVar(fldTrigger::TAG, strval(Util::mkTZOffset($r['trigger']->format('U'), true)),
		            				 false, [ 'VALUE' => 'date-time' ]);
	            	else
		            	$int->addVar(fldTrigger::TAG, Util::cnvDuration(true, $r['trigger']), false,
		            				 [ 'VALUE' => 'duration', 'RELATED' => isset($r['related']) ? $r['related'] : 'start' ]);
	        // attachments were currently not supported by plugin
	        //    	if (isset($r['attachment'])) {
	        //
            //			$p = $int->savePos();
	        //    		$int->addVar(fldAttach::TAG);
            //			$int->addVar(fldAttach::SUB_TAG[1], $att->create($r['attachment']));
            //			$int->restorePos($p);
            //		}
	        //    	if (isset($r['email']))
	        //    		$int->addVar(fldMailOther::TAG, $r['email']);
	        //    	if (isset($r['summary']))
	        //   		$int->addVar(fldSummary::TAG, $r['summary']);
	        //    	if (isset($r['description']))
	        //    		$int->addVar(fldBody::TAG, $r['description']);
		            $int->restorePos($p);
	            }
	            $int->restorePos($ip);
		    	break;

		    //  6 - Related to
    		case 6:
	            $int->addVar($tag[1], DataStore::TYP_DATA.$val);

			// 7 - Skip
			case 7:
	            break;
			}
		}

		// add missing data
		if ($rec['complete'] == 1)
			$int->addVar(fldCompleted::TAG, strval(Util::unxTime($rec['date'].'T'.$rec['time'])));
		if ($int->getVar(fldStatus::TAG) === null)
			$int->addVar(fldStatus::TAG, '0', false, [ 'X-PC' => 0 ]);

		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

			$int->setTop();
            Msg::InfoMsg($int, 'Internal record');
		}

		return $int;
	}

	/**
	 * 	Swap internal to external record
	 *
	 *	@param 	- Internal document
	 * 	@return - External document or null
	 */
	private function _swap2ext(XML &$int): array {

		// get record id
		$rid = $int->getVar('extID');

		// is record a calendar?
	    if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

	    	$attr = $int->getVar(fldAttribute::TAG);
	    	$rec  = [
					'tasklist_id' => substr($rid, 1),
					'name'		  => $int->getVar(fldGroupName::TAG),
					'showalarms'  => $attr & fldAttribute::ALARM,
					'editable'	  => $attr & fldAttribute::EDIT,
	    			];
	    } else {

			//	$att = Attachment::getInstance();
			$rec = [
					'list' 			=> intval(substr($int->getVar('extGroup'), 1)),
					'id'			=> substr($rid, 1),
					'description'	=> null,
					'flagged'		=> 0,
					''
			];

			// disable attachment size check for WebDAV
    	    $hack = $this->_cnf->getVar(Config::HACK);
			$this->_cnf->updVar(Config::HACK, $hack | Config::HACK_SIZE);
			$pc   = '0';

			// swap data
			$int->getVar('Data');
			foreach (self::MAP as $key => $tag) {

				$ip = $int->savePos();

				switch ($tag[0]) {
				// 	0 - String
			    case 0:
					if ($val = $int->getVar($tag[1], false))
						$rec[$key] = Encoding::cnvStr($val, false);
					if ($key == 'status') {

	    		    	// if task is completed set to 100%
	    		    	if ($val == 'COMPLETED')
	    		    		$pc = 1;
						elseif ($val = $int->getAttr('X-PC'))
	    	       		    $pc = number_format($val / 100, 2, '.', '');
					    $rec['complete'] = $pc;
					}
					break;

				// 	1 - Category array - currently not supported by plugin
			    // case 1:
			    //	$int->xpath($tag[1], false);
			    //	while ($val = $int->getItem()) {
			   	//        if (!isset($rec[$key]))
	    	    //           	$rec[$key] = [];
	    	    //           	$rec[$key][] = Encoding::cnvStr($val, false);
			    //	}
				//	break;

				//  2 - Date
			    case 2:
			    	if ($val = $int->getVar($tag[1], false)) {

						$t	   	   = new \DateTime(gmdate(Config::UTC_TIME, intval($val)), new \DateTimeZone('UTC'));
						$rec[$key] = $t->format('Y-m-d');
		      			if ($key == 'date')
	    	 		    	$rec['time'] = $t->format('H:i');
	     			    else
	     			    	$rec['starttime'] = $t->format('H:i');
			    	}
			    	break;

				//  3 - Recurrence array
			   	case 3:
					$int->xpath($tag[1], false);
					while ($int->getItem() !== null) {

						if (!isset($rec[$key]))
							$rec[$key] = [ ];
						foreach (fldRecurrence::RFC_SUB as $k => $v) {

							if (substr($k, 0, 2) == 'X-')
								continue;
							$p = $int->savePos();
							$int->xpath($v, false);
							while (($val = $int->getItem()) !== null) {

								if ($k == 'UNTIL') {

									$val = new \DateTime(gmdate(Config::UTC_TIME, intval($val)), new \DateTimeZone('UTC'));
									$val->setTimezone(new \DateTimeZone($this->_hd->RCube->config->get('timezone')));
								}
								$rec[$key][$k] = $val;
							}
							$int->restorePos($p);
						}
					}
	       		    break;

				//  4 - Attendee and organizer array
	    		// case 4:
				//	$int->xpath(fldOrganizer::TAG, false);
				//	while ($val = $int->getItem()) {
				//
			    //		if (!isset($rec[$key]))
	            //       		$rec[$key] = [];
			    //		$a = $int->getAttr();
		        //        $rec[$key][] = [
	    	    //            'role'   => 'ORGANIZER',
				//			'cutype' => isset($a['CUTYPE']) ? $a['CUTYPE'] : '',
	        	//		    'rsvp'   => isset($a['RSVP']) ? $a['RSVP'] == 'true' ? 1 : 0 : '',
	        	//		    'email'  => substr($val, 7),
		        //        	'name'	 => isset($a['CN']) ? Encoding::cnvStr($a['CN'], false) : '',
	        	//	    	'status' => isset($a['PARTSTAT']) ? $a['PARTSTAT'] : '',
	            //   		 ];
				//	}
				//	$int->restorepos($ip);
				//
				//	$int->xpath($tag[1], false);
				//	while ($val = $int->getItem()) {
				//
			    //		if (!isset($rec[$key]))
	            //      		$rec[$key] = [];
			    //		$a = $int->getAttr();
		        //        $rec[$key][] = [
	    	    //            'role'   => isset($a['ROLE']) ? $a['ROLE'] : '',
				//			'cutype' => isset($a['CUTYPE']) ? $a['CUTYPE'] : '',
	        	//		    'rsvp'   => isset($a['RSVP']) ? $a['RSVP'] == 'true' ? 1 : 0 : '',
	        	//		    'email'  => substr($val, 7),
		        //        	'name'	 => isset($a['CN']) ? Encoding::cnvStr($a['CN'], false) : '',
	        	//	    	'status' => isset($a['PARTSTAT']) ? $a['PARTSTAT'] : '',
	            //   		 ];
	            //    }
	            //    break;

			    //  5 - VALARM
			    case 5:
					$int->xpath($tag[1], false);
					$n = -1;
			    	while ($int->getItem() !== null) {

			    		$p = $int->savePos();
	                    if (!isset($rec[$key]))
	                    	$rec[$key] = [];

	                    if ($val = $int->getVar(fldAlarm::SUB_TAG['VCALENDAR/%s/VALARM/ACTION'], false))
		                    $rec[$key][++$n]['action'] = $val;

		                $int->restorePos($p);
	                    if (($val = $int->getVar(fldTrigger::TAG, false)) !== null) {

			                if ($v = $int->getAttr('RELATED'))
			                    $rec[$key][$n]['related'] = strtolower($v);
			                else
			                    $rec[$key][$n]['related'] = 'start';
	                    	if ($int->getAttr('VALUE') == 'duration') {

	                        	if (substr($rec[$key][$n]['trigger'] = Util::cnvDuration(false, $val), 0, 1) != '-')
	                        		$rec[$key][$n]['trigger'] = '+'.$rec[$key][$n]['trigger'];
	                    	} else
		                        $rec[$key][$n]['trigger'] = new \DateTime(gmdate(Config::UTC_TIME,
		                        		intval(Util::mkTZOffset($val, true))), new \DateTimeZone('UTC'));
	                    }

					//	  $int->restorePos($p);
	                //    if ($val = $int->getVar(fldAttach::TAG, false))
	                //    	$rec[$key][$n]['attachment'] = $att->read($int->getVar(fldAttach::SUB_TAG[1], false));
					//
		            //    $int->restorePos($p);
	                //    if ($val = $int->getVar(fldMailOther::TAG, false))
		            //        $rec[$key][$n]['email'] = Encoding::cnvStr($val, false);
					//
		            //    $int->restorePos($p);
	                //    if ($val = $int->getVar(fldSummary::TAG, false))
		            //        $rec[$key][$n]['summary'] = Encoding::cnvStr($val, false);
					//
		            //    $int->restorePos($p);
	                //    if ($val = $int->getVar(fldBody::TAG, false))
		            //        $rec[$key][$n]['description'] = Encoding::cnvStr($val, false);

	                	$int->restorePos($p);
					}
			    	break;

			    // 6 - Related to
	    		case 6:
	    			if (!($val = $int->getVar($tag[1], false)))
	    				break;
	    			$rec[$key] = substr($val, 1);

				// 7 - Skip
				case 7:
	                break;
				}

				$int->restorePos($ip);
			}

			// enable attachment size check
			$this->_cnf->updVar(Config::HACK, $hack);

			if (!isset($rec['uid']))
			    $rec['uid'] = strtoupper(md5(strval(time()).uniqid(strval(rand()))));

			if (!isset($rec['recurrence']))
			    $rec['recurrence'] = [];

		    if(!isset($rec['parent_id']))
		    	$rec['parent_id'] = null;
	    }

	    // show record
        if ($this->_cnf->getVar(Config::DBG_SCRIPT) && $this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document') {

        	$xr = $rec;
        	if (isset($xr['attachments']))
        		// replace any binary data
	            foreach ($xr['attachments'] as $k => $v)
    	            $xr['attachments'][$k]['data'] = Trace::BIN_DATA;
 			Msg::InfoMsg($xr, 'Swapped external record');
        }

		return $rec;
	}

	/**
	 *  Get record
	 *
	 *  @param  - Record Id
	 *  @return - External record or null on error
	 */
	private function _get(string $rid): ?array {

	    // tasklist_database_driver.php:get_task()
		$sql = 'SELECT * FROM '.$this->_tab[self::TASKS].
               ' WHERE tasklist_id = ?'.
               ' AND task_id = ?'.
               ' AND del = ?';
		if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
            Msg::InfoMsg($sql);

        $rec = [];
        do {

        	$res = $this->_hd->RCube->db->query($sql, substr($this->_ids[$rid][Handler::GROUP], 1), substr($rid, 1), 0);
		} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		do {

			$recs = [];
			while ($r = $this->_hd->RCube->db->fetch_assoc($res))
				$recs[] = $r;
		} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		foreach ($recs as $rec) {

            // tasklist_database_driver.php:_read_postprocess()
            $rec['id']      = $rec['task_id'];
            $rec['list']    = $rec['tasklist_id'];
            $rec['changed'] = new \DateTime($rec['changed'], new \DateTimeZone('UTC'));

            if ($rec['tags'])
	            $rec['tags'] = array_filter(explode(',', $rec['tags']));

	        // no parent given?
            if (!$rec['parent_id'])
                unset($rec['parent_id']);

            // tasklist_database_driver.php:unserialize_alarms()
            // decode serialized alarms
            if ($rec['alarms']) {

                // decode json serialized alarms
                if ($rec['alarms'] && $rec['alarms'][0] == '[') {

                    $rec['valarms'] = json_decode($rec['alarms'], true);
                    foreach ($rec['valarms'] as $i => $a) {

                    	if (!is_array($a['trigger']) && $a['trigger'][0] == '@') {

                    		$t = substr($a['trigger'], 1, 19);
                    		$t = Util::mkTZOffset(Util::unxTime($t));
                            $rec['valarms'][$i]['trigger'] = new \DateTime(gmdate(Config::UTC_TIME,
                            									intval($t)), new \DateTimeZone('UTC'));
                    	} elseif (isset($a['trigger']['date']))
                            $rec['valarms'][$i]['trigger'] = new \DateTime($a['trigger']['date'],
                            									new \DateTimeZone('UTC'));
                        else
                            $rec['valarms'][$i]['trigger'] = $a['trigger'];
                    }
                }
            }
            // convert legacy alarms data
            elseif ($rec['alarms'] && strlen($rec['alarms'])) {

                list($v, $a) = explode(':', $rec['alarms'], 2);
                if ($v = libcalendaring::parse_alarm_value($v))
                    $rec['valarms'] = [ [ 'action' => $a, $v[3] ? [ 'trigger' => $v[0] ] : [] ] ];
            }
            unset($rec['alarms']);

            // decode serialze recurrence rules
            if ($rec['recurrence']) {

                // tasklist_database_driver.php:unserialize_recurrence()
                if (strlen($rec['recurrence'])) {

                    $rec['recurrence'] = json_decode($rec['recurrence'], true);
                    foreach ($rec['recurrence'] as $k => $v) {

                        if (is_string($v) && $v[0] == '@')
                            $rec['recurrence'][$k] = new \DateTime(substr($v, 1, 19), new \DateTimeZone('UTC'));
                    }
                }
            }

            unset($rec['task_id'], $rec['tasklist_id'], $rec['created']);
        }

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

	   	// create external record
		$rec = self::_swap2ext($int);

		// create a new task list?
		if ($int->getVar('Type') == DataStore::TYP_GROUP) {

			// tasklist_database_driver.php:create_list()

            $sql = 'INSERT INTO '.$this->_tab[self::LISTS].
                   ' (user_id, name, color, showalarms) VALUES (?, ?, ?, ?)';
            if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
                Msg::InfoMsg($sql);

            do {

            	if ($this->_hd->RCube->db->query($sql, $this->_hd->RCube->user->ID, strval($rec['name']), '000000', $rec['showalarms'] ? 1 : 0))
	            	$rid = $this->_hd->RCube->db->insert_id($this->_tab[self::LISTS]);
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		    if (!isset($rid) || !$this->_hd->Retry)
				return null;

	        // enable new calendar for synchronization
		    $this->_pref = $this->_pref.Handler::TASK_FULL.$rid.';';
   			$this->_hd->RCube->user->save_prefs([ 'syncgw' => $this->_pref ]);

	    	// add record to internal managament
	     	$rid = DataStore::TYP_GROUP.$rid;
   	    	if ($a = $int->getVar(fldAttribute::TAG))
   	    		$a = intval($a);
    		else {

   	    		$a = fldAttribute::READ|fldAttribute::WRITE;
	       		if ($rec['editable'])
	       			$a |= fldAttribute::EDIT|fldAttribute::DEL;
	       		if ($rec['showalarms'])
	       			$a |= fldAttribute::ALARM;
    		}
	     	$this->_ids[$rid] = [
					Handler::GROUP 	=> '',
			        Handler::NAME  	=> $rec['name'],
	 	       		Handler::ATTR	=> $a,
	   				Handler::LOAD	=> 0,
    		];

			// save record id
			$int->updVar('extGroup', $rid);

		} else {

		    // if we have no group, we switch to default group
			if (!($gid = $int->getVar('extGroup')) || !isset($this->_ids[$gid])) {

				// set default group
				foreach ($this->_ids as $k => $parms) {

					if (substr($k, 0, 1) == DataStore::TYP_GROUP && ($parms[Handler::ATTR] & fldAttribute::EDIT)) {
				    	$gid = $k;
				       	break;
				    }
				}
			    $int->updVar('extGroup', $gid);
			}

		    // tasklist_database_driver.php:create_task()
	        if (isset($rec['valarms'])) {

	            // tasklist_database_driver.php:serialize_alarms()
	            foreach ($rec['valarms'] as $k => $v) {

	            	if ($v['trigger'] instanceof \DateTime) {

	            		$rec['valarms'][$k]['trigger']->setTimestamp(intval(Util::mkTZOffset(
	            								$rec['valarms'][$k]['trigger']->format('U'))));
	            		$rec['valarms'][$k]['trigger'] = '@'.$v['trigger']->format('c');
	            	}
	            }
	            $rec['alarms'] = json_encode($rec['valarms']);
	        }

	        // tasklist_database_driver.php:serialize_recurrence()
	        if (is_array($rec['recurrence'])) {

	            foreach ($rec['recurrence'] as $k => $v) {

	            	if ($v instanceof \DateTime) {
	            		$v->setTimestamp(intval(Util::mkTZOffset($v->format('U'))));
	            		$rec['recurrence'][$k] = '@'.$v->format('c');
	            	}
	            }
	            $rec['recurrence'] = json_encode($rec['recurrence']);
	        }

	        if (array_key_exists('complete', $rec))
	            $rec['complete'] = number_format(floatval($rec['complete']), 2, '.', '');

	        foreach ([ 'parent_id', 'date', 'time', 'startdate', 'starttime', 'alarms', 'recurrence', 'status'] as $c) {

	            if (empty($rec[$c]))
	                $rec[$c] = null;
	        }
	        if (empty($rec['title']))
	        	$rec['title'] = '';

	        // tasklist_database_driver.php:_get_notification()
	        $n = null;
	        if (isset($rec['valarms']) && $rec['valarms'] &&
	        	$rec['complete'] < 100.0 && !empty($rec['status']) && $rec['status'] != 'COMPLETED') {

	            if ($a = libcalendaring::get_next_alarm($rec, 'task'))
		            if ($a['time'])
	    	            $n = date('Y-m-d H:i:s', intval($a['time']));
	        }

	        $sql = sprintf('INSERT INTO '.$this->_tab[self::TASKS].
	                       ' (tasklist_id, uid, parent_id, created, changed, title, date, time, startdate, starttime,'.
	                       ' description, tags, flagged, complete, status, alarms, recurrence, notify)'.
	                       ' VALUES (?, ?, ?, %s, %s, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
	        			   $this->_hd->RCube->db->now(), $this->_hd->RCube->db->now());
	        if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
	            Msg::InfoMsg($sql);

	        if (!isset($rec['tags']))
	            $rec['tags'] = [];

	        do {

		        if ($this->_hd->RCube->db->query($sql, $rec['list'], $rec['uid'], $rec['parent_id'], $rec['title'], $rec['date'],
		                       $rec['time'], $rec['startdate'], $rec['starttime'], strval($rec['description']),
	    	                   join(',', $rec['tags']), $rec['flagged'] ? 1 : 0, $rec['complete'] ? $rec['complete'] : 0,
	        	               strval($rec['status']), $rec['alarms'], $rec['recurrence'], $n))
	        		$rid = $this->_hd->RCube->db->insert_id($this->_tab[self::TASKS]);
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		    if (!$this->_hd->Retry)
				return null;

			// add record to internal managament
	       	$rid = DataStore::TYP_DATA.$rid;

			// add records to known list
			$this->_ids[$rid] = [
					Handler::GROUP 	=> $gid,
					Handler::CID	=> '',
					Handler::ATTR	=> fldAttribute::READ|fldAttribute::WRITE|fldAttribute::EDIT|fldAttribute::DEL,
			];

			// save record id
			$int->updVar('extID', $rid);
			$int->updVar('extGroup', $gid);
		}

		$id = $this->_ids[$rid];
        $id[Handler::ATTR] = fldAttribute::showAttr($id[Handler::ATTR]);
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

       	// get record id
		$rid = $int->getVar('extID');

		// create external record
		$rec = self::_swap2ext($int);

		// is record a task list?
        if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			// swap data
            $this->_ids[$rid][Handler::NAME]  	= $rec['name'];
	       	if ($this->_ids[$rid][Handler::ATTR] & fldAttribute::DEFAULT)
	            $this->_ids[$rid][Handler::ATTR] = fldAttribute::READ|fldAttribute::WRITE|fldAttribute::DEFAULT;
			else
	            $this->_ids[$rid][Handler::ATTR] = fldAttribute::READ|fldAttribute::WRITE;
            if ($rec['editable'])
	       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::EDIT;
	       	if ($rec['showalarms'])
	       		$this->_ids[$rid][Handler::ATTR] |= fldAttribute::ALARM;

        	// tasklist_database_driver.php:edit_list()
            $sql = 'UPDATE '.$this->_tab[self::LISTS].
                   ' SET name = ?, showalarms = ?'.
                   ' WHERE tasklist_id = ? AND user_id = ?';
            if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
                Msg::InfoMsg($sql);
            do {

               	if ($res = $this->_hd->RCube->db->query($sql, $rec['name'], $rec['showalarms'] ? 1 : 0,
               		$rec['tasklist_id'], $this->_hd->RCube->user->ID))
               		if ($this->_hd->RCube->db->affected_rows($res))
               			return true;
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

			return false;
	    }

	    // tasklist_database_driver.php:create_task()
	    if (!isset($rec['valarms']))
	    	$rec['valarms'] = null;

        if (is_array($rec['valarms'])) {

            // tasklist_database_driver.php:serialize_alarms()
            foreach ($rec['valarms'] as $k => $v) {

            	if ($v['trigger'] instanceof \DateTime) {

	            	$rec['valarms'][$k]['trigger']->setTimestamp(intval(
	            				Util::mkTZOffset($rec['valarms'][$k]['trigger']->format('U'))));
                    $rec['valarms'][$k]['trigger'] = '@'.$v['trigger']->format('c');
            	}
            }
            $rec['alarms'] = json_encode($rec['valarms']);
        }

        if (is_array($rec['recurrence'])) {

            // tasklist_database_driver.php:serialize_recurrence()
            foreach ($rec['recurrence'] as $k => $v) {

            	if ($v instanceof \DateTime) {

	            	$v->setTimestamp(intval(Util::mkTZOffset($v->format('U'))));
            		$rec['recurrence'][$k] = '@'.$v->format('c');
            	}
            }
            $rec['recurrence'] = json_encode($rec['recurrence']);
        }

        if (array_key_exists('complete', $rec))
            $rec['complete'] = number_format(floatval($rec['complete']), 2, '.', '');

        foreach ([ 'parent_id', 'date', 'time', 'startdate', 'starttime', 'alarms', 'recurrence', 'status'] as $c) {

            if (empty($rec[$c]))
                $rec[$c] = null;
        }

        // tasklist_driver.php:_get_notification()
        $n = null;
        if ($rec['valarms'] && $rec['complete'] < 100.0 && !empty($rec['status']) && $rec['status'] != 'COMPLETED') {

            $a = libcalendaring::get_next_alarm($rec, 'task');
            if (isset($a['time']))
                $n = date('Y-m-d H:i:s', intval($a['time']));
        }

        if (!isset($rec['tags']))
            $rec['tags'] = [];

        $sql = sprintf('UPDATE '.$this->_tab[self::TASKS].
                       ' SET changed = %s, title = ?, date = ?, time = ?, startdate = ?,'.
                       ' starttime = ?, description = ?, tags = ?, flagged = ?, complete = ?, status = ?,'.
                       ' alarms = ?, recurrence = ?, notify = ?, parent_id = ?'.
                       ' WHERE task_id = ? AND tasklist_id = ?',
                        $this->_hd->RCube->db->now());
        if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
            Msg::InfoMsg($sql);

        do {

         	if (($res = $this->_hd->RCube->db->query($sql, $rec['title'], $rec['date'], $rec['time'], $rec['startdate'],
	                              $rec['starttime'], strval($rec['description']), join(',', $rec['tags']),
    	                          $rec['flagged'] ? 1 : 0, $rec['complete'] ? $rec['complete'] : 0,
        	                      strval($rec['status']), $rec['alarms'], $rec['recurrence'], $n,
            	                  $rec['parent_id'], $rec['id'], $rec['list'])) &&
					       		  $this->_hd->RCube->db->affected_rows($res))
   				return true;
		} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		return false;
	}

	/**
	 * 	Delete record
	 *
	 * 	@param 	- Record id
	 * 	@return - true=Ok, false=Error
	 */
	private function _del(string $rid): bool {

 	    // delete calendar
        if (substr($rid, 0, 1) == DataStore::TYP_GROUP) {

			// delete all sub records
           	foreach ($this->_ids as $id => $parms) {

                if ($parms[Handler::GROUP] == $rid)
                    if (!self::_del($id))
   	                	return false;
            }

    	    // tasklist_database_driver.php:delete_list()
            // delete all tasks linked with this list
            $sql = 'DELETE FROM '.$this->_tab[self::TASKS].
                   ' WHERE tasklist_id = ?';
           	if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
                Msg::InfoMsg($sql);

            do {

        		$this->_hd->RCube->db->query($sql, substr($rid, 1));
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

      		// remove records in group
   			foreach ($this->_ids as $k => $v)
        		if ($v[Handler::GROUP] == $rid)
        			unset($this->_ids[$k]);
			// and group itself
			unset($this->_ids[$rid]);

            // delete list record
            $sql = 'DELETE FROM '.$this->_tab[self::LISTS].
                   ' WHERE tasklist_id = ? '.
                   ' AND user_id = ?';
           	if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
                Msg::InfoMsg($sql);

            do {

	        	if ($res = $this->_hd->RCube->db->query($sql, substr($rid, 1), $this->_hd->RCube->user->ID))
	        		if ($this->_hd->RCube->db->affected_rows($res))
	        			return true;
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		    if (!$this->_hd->Retry)
				return false;

            // disable calendar for synchronization
            $this->_pref = str_replace(Handler::TASK_FULL.substr($id, 1).';', '', $this->_pref);
   			$this->_hd->RCube->user->save_prefs([ 'syncgw' => $this->_pref ]);

       } else {

	        // tasklist_database_driver.php:delete_task()
    	    $sql = 'DELETE FROM '.$this->_tab[self::TASKS].
        	       ' WHERE task_id = ?';
	        if ($this->_cnf->getVar(Config::DBG_SCRIPT) != 'Document')
    	        Msg::InfoMsg($sql);

    	    do {

    	    	if ($res = $this->_hd->RCube->db->query($sql, substr($rid, 1)))
    	    		$this->_hd->RCube->db->affected_rows($res);
			} while ($this->_hd->chkRetry(DataStore::TASK, __LINE__));

		    if (!$this->_hd->Retry)
				return false;
       }

       // remove record from list
	   unset($this->_ids[$rid]);

	   return true;
	}

}
