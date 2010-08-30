<?php

/**************************************************************************************************

Run User Tool - Version 1.0

This is a special user tool in that it does not perform any action itself, but
instead can perform multiple actions, by running other user tools.  It is used
to allow single commands to run one or more user tools to act (in turn) on one
or more staffs.  This tool can be run using several different command entries,
each specifying different parameter lists, so that each accomplishes different
and powerful results.

Beginner usage:

- Interactive "wizard"
	prompts for a staff subset,
	prompts for a user tool,
	runs the selected user tool on each selected staff in turn

	Name: Run User Tool (prw)
	Command: php\php.exe scripts\prw_RunUserTool.php

Intermediate usages:

- Dedicated to a particular staff subset
	prompts for a user tool,
	runs the selected user tool on each specified staff in turn

	Name: Run User Tool (prw) - <staffsubset> [or any applicable name]
	Command: php\php.exe scripts\prw_RunUserTool.php <staffsubset>

	where <staffsubset> is one of:
		all, visibile, hidden, audible, or muted

- Dedicated to a particular user tool
	prompts for a staff subset,
	runs the specified user tool on each selected staff in turn

	Name: Run User Tool (prw) - <usertool> [or any applicable name]
	Command: php\php.exe scripts\prw_RunUserTool.php "<usertool>"

	where <usertool> is an existing command name (if the same command name
	appears in more than one tool group, add a "<toolgroup>:" prefix)

Advanced usage:

- Dedicated to a particular staff subset and user tool
	immediately runs the specified user tool on each specified staff in turn

	Name: [any applicable name]
	Command: php\php.exe scripts\prw_RunUserTool.php <staffsubset> "<usertool>"

- Dedicated to a particular sequence of user tools
	prompts for a staff subset,
	runs first specified user tool on each selected staff in turn
	continues with each remaining user tool in turn

	Name: [any applicable name]
	Command: php\php.exe scripts\prw_RunUserTool.php "<usertool1>" "<usertool2>" ...

Expert usage:

- Dedicated to particular staff subset(s) for each of a sequence of user tools

	String any number and combination of "<usertool>" and <staffsubset>
	parameters.  Each user tool encountered will be run in turn against
	each staff in the staff subset that was last specified.  A user tool
	occuring in the sequence before any staff subset has been specified
	will trigger a prompt for the initial staff subset.

FOR ALL USAGES:

	Input Type: File Text (mandatory)
	Options: Compress Input (optional)
		 Returns File Text (mandatory)
		 Prompts for User Input (mandatory)

Copyright © 2010 by Randy Williams
All Rights Reserved

**************************************************************************************************/

require_once("lib/nwc2clips.inc");
require_once("lib/nwc2config.inc");
require_once("lib/nwc2gui.inc");

define("RUT_PAGEWIDTH", 400);

/*************************************************************************************************/

function getSongItem ($advance = false) {
	static $file = null;
	static $read = true;
	static $item = null;

	if (!$file)
		$file = fopen("compress.zlib://php://stdin", "r");

	if ($read)
		$item = fgets($file);

	if (trim($item) == NWC2_ENDFILETXT) {
		$read = false;
		return false;
	}

	if (!$item)
		trigger_error("Could not find song ending tag", E_USER_ERROR);

	$read = $advance;
	return $item;
}

// parse header items and staff data from a song's items
class ParseSong {
	var $SongHeader = null;
	var $SongFooter = null;

	var $Comments = array();
	var $Version = "";

	var $HeaderItems = array();
	var $HeaderValues = array();

	var $StaffData = array();

	function __construct () {
		while (getSongItem()) {
			$hdr = getSongItem(true);

			switch (NWC2ClassifyLine($hdr)) {
				case NWCTXTLTYP_FORMATHEADER:
					if (preg_match('/^'.NWC2_STARTFILETXT.'\(([0-9]+)\.([0-9]+)/', $hdr, $m)) {
						$this->Version = "$m[1].$m[2]";
						break 2;
					}

					trigger_error("Unrecognized notation song format header", E_USER_ERROR);

				case NWCTXTLTYP_COMMENT:
					$this->Comments[] = substr($hdr, 1);
					break;
			}
		}

		if (!getSongItem())
			trigger_error("Format error in the song text", E_USER_ERROR);

		$this->SongHeader = NWC2_STARTFILETXT."(".$this->Version.")";
		$this->SongFooter = NWC2_ENDFILETXT;

		while (getSongItem() && $this->isHeaderItem(getSongItem(), true))
			$this->HeaderItems[] = getSongItem(true);

		while (getSongItem() && !$this->isHeaderItem(getSongItem(), false))
			$this->StaffData[] = new ParseStaff();
	}

	private function isHeaderItem ($item, $capture) {
		$ObjType = NWC2GetObjType($item);

		if (NWC2ClassifyObjType($ObjType) == NWC2OBJTYP_FILEPROPERTY) {
			if ($capture) {
				$o = new NWC2ClipItem($item);

				if ($ObjType != "Font") {
					// we expect at most one of these per song
					$this->HeaderValues[$ObjType] = $o->Opts;
				}
				else {
					// we expect one or more of these per song
					if (!isset($this->HeaderValues[$ObjType]))
						$this->HeaderValues[$ObjType] = array();

					$this->HeaderValues[$ObjType][] = $o->Opts;
				}
			}

			return true;
		}

		return false;
	}

	function GetClipText ($staffindex) {
		$StaffData =& $this->StaffData[$staffindex];

		$trans = $StaffData->HeaderValues["StaffInstrument"]["Trans"];
		$startingbar = $this->HeaderValues["PgSetup"]["StartingBar"];

		$cliptext = array();

		$cliptext[] = NWC2_STARTCLIP."({$this->Version},Single)".PHP_EOL;

		$cliptext[] = "|Fake|Instrument|Trans:$trans".PHP_EOL;
		$cliptext[] = "|Context|Bar:$startingbar,AtStart".PHP_EOL;

		$cliptext = array_merge($cliptext, $StaffData->BodyItems);

		$cliptext[] = NWC2_ENDCLIP.PHP_EOL;

		return $cliptext;
	}

	function IsContextInfo ($item) {
		$o = new NWC2ClipItem($item);

		return $o->IsContextInfo();
	}

	function PutClipText ($staffindex, $cliptext) {
		$StaffData =& $this->StaffData[$staffindex];

		$clip = new NWC2Clip($cliptext);

		// remove any fake and context items left at the beginning
		while ($clip->Items && $this->IsContextInfo($clip->Items[0]))
			array_shift($clip->Items);

		if ($StaffData->BodyItems === $clip->Items)
			return false;

		$StaffData->BodyItems = $clip->Items;
		return true;
	}

	function OutputSongText ($compress = false) {
		$prefix = ($compress ? "compress.zlib://" : "");

		$f = fopen($prefix."php://stdout", "w");

		fwrite($f, $this->SongHeader.PHP_EOL);
		fwrite($f, implode("", $this->HeaderItems));

		foreach ($this->StaffData as $StaffData) {
			fwrite($f, implode("", $StaffData->HeaderItems));
			fwrite($f, implode("", $StaffData->BodyItems));
		}

		fwrite($f, $this->SongFooter.PHP_EOL);

		fclose($f);
	}
};

// parse header items and body items for a staff from a song's items
class ParseStaff {
	var $HeaderItems = array();
	var $HeaderValues = array();

	var $BodyItems = array();

	function ParseStaff () {
		while (getSongItem() && $this->isHeaderItem(getSongItem(), true))
			$this->HeaderItems[] = getSongItem(true);

		while (getSongItem() && !$this->isHeaderItem(getSongItem(), false))
			$this->BodyItems[] = getSongItem(true);
	}

	private function isHeaderItem ($item, $capture) {
		$ObjType = NWC2GetObjType($item);

		$ObjTypeClass = NWC2ClassifyObjType($ObjType);
		if (($ObjTypeClass == NWC2OBJTYP_STAFFPROPERTY) ||
		    ($ObjTypeClass == NWC2OBJTYP_STAFFLYRIC)) {
			if ($capture) {
				$o = new NWC2ClipItem($item);

				if ($ObjType != "StaffProperties") {
					// we expect at most one of these per staff
					// handles empty staff followed by another staff
					if (isset($this->HeaderValues[$ObjType]))
						return false;

					$this->HeaderValues[$ObjType] = $o->Opts;
				}
				else {
					// we expect one or more of these per staff
					if (!isset($this->HeaderValues[$ObjType]))
						$this->HeaderValues[$ObjType] = array();

					$this->HeaderValues[$ObjType][] = $o->Opts;
				}
			}

			return true;
		}

		return false;
	}
}

/*************************************************************************************************/

class NWC2UserTools {
	public $builtin = array();
	public $userdefined = array();

	function __construct () {
		$this->builtin = $this->getusertools(NWC2CONFIG_AppFolder);
		$this->userdefined = $this->getusertools(NWC2CONFIG_ConfigFolder);
	}

	function getusertools ($inidir) {
		$inifile = "$inidir/nwc2UserTools.ini";

		if (!file_exists($inifile))
			return array();

		$inidata = file($inifile);

		$usertools = array();
		$section = "";

		foreach ($inidata as $iniline) {
			$iniline = trim($iniline);

			// skip blank lines and comment lines
			if (!$iniline || ($iniline[0] == ";"))
				continue;

			// handle section header lines
			if (preg_match('/^\[([^\]]*)\]/', $iniline, $m)) {
				$section = trim($m[1]);
				continue;
			}

			// skip unspecified data and config data
			if (!$section || (strtolower($section) == "config"))
				continue;

			if (preg_match('/^([^=]*)=(.*)$/', $iniline, $m)) {
				$toolname = trim($m[1]);
				$toolexec = trim($m[2]);

				$this->remove_outer_quoting($toolexec);

				if (preg_match('/^(\d+)\s*,(.*)$/', $toolexec, $m)) {
					$toolflags = intval($m[1]);
					$toolexec = trim($m[2]);

					$this->remove_outer_quoting($toolexec);
				}
				else {
					$toolflags = 0;
				}

				$usertools[$section][$toolname]["command"] = $toolexec;
				$usertools[$section][$toolname]["cmdflags"] = $toolflags;
			}
		}

		uksort($usertools, array($this, "sort"));

		foreach ($usertools as &$sectiondata)
			uksort($sectiondata, array($this, "sort"));

		return $usertools;
	}

	function remove_outer_quoting (&$value) {
		if (preg_match('/^"([^"]*)"$/', $value, $m))
			$value = $m[1];
		else if (preg_match("/^'([^']*)'$/", $value, $m))
			$value = $m[1];
	}

	function sort ($a, $b) {
  		return strtolower($a) > strtolower($b);
	}
}

/*************************************************************************************************/

class wizardPanel extends wxPanel
{
	private $nextButton = null;
	private $panelSizer = null;

	private $wxID = wxID_HIGHEST;

	function __construct ($parent, $nextButton, $prompt) {
		parent::__construct($parent);

		$this->nextButton = $nextButton;
		$this->updateNextButton();

		$this->panelSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($this->panelSizer);

		// create prompt field at maximum panel size, to force out other fields
		$statictext = new wxStaticText($this, $this->new_wxID(), $prompt, wxDefaultPosition,
						new wxSize(RUT_PAGEWIDTH - 20, -1));
		$this->panelSizer->Add($statictext, 0, wxALL, 10);
	} 

	// override this method to control when the next button is valid
	protected function isNextValid () {
		return true;
	}

	// call this when the validity of the next button may have changed
	final protected function updateNextButton () {
		$this->nextButton->Enable($this->isNextValid());
	}

	final protected function newRow () {
		$rowSizer = new wxBoxSizer(wxHORIZONTAL);
		$this->panelSizer->Add($rowSizer, 0, wxGROW|wxLEFT|wxRIGHT|wxBOTTOM, 10);
		return $rowSizer;
	}

	final protected function doFit () {
		$this->panelSizer->Fit($this);
	}

	final protected function new_wxID () {
		return ++$this->wxID;
	}

	final protected function cur_wxID () {
		return $this->wxID;
	}
}

/*************************************************************************************************/

class staffPanel extends wizardPanel
{
	const prompt = "Select all of the staff parts that you want to send to a user tool:";

	private $SongData = null;

	private $grouplist = array();
	private $stafflist = array();

	private $groupobject = null;
	private $staffobject = null;

	private $groupselected = array();
	private $staffselected = array();

	function __construct ($parent, $nextButton, $SongData, $staffsubset) {
		parent::__construct($parent, $nextButton, self::prompt);

		$this->SongData = $SongData;

		foreach ($this->SongData->StaffData as $StaffData) {
			$groupname = $StaffData->HeaderValues["AddStaff"]["Group"];
			$staffname = $StaffData->HeaderValues["AddStaff"]["Name"];

			if (!in_array($groupname, $this->grouplist)) {
				$this->grouplist[] = $groupname;
				$this->groupselected[] = false;
			}

			$this->stafflist[] = $staffname;
			$this->staffselected[] = false;
		}

		//--------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		//-------------------------------------------------
		// groups listbox

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 3);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Groups:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, wxDefaultSize,
					 nwc2gui_wxArray($this->grouplist), wxLB_MULTIPLE);
		$colSizer->Add($listbox, 1, wxGROW|wxALIGN_LEFT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectGroup"));
		$this->groupobject = $listbox;

		//-------------------------------------------------
		// staffs listbox

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 5, wxLEFT, 10);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Staffs:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, wxDefaultSize,
					 nwc2gui_wxArray($this->stafflist), wxLB_MULTIPLE);
		$colSizer->Add($listbox, 1, wxGROW|wxALIGN_RIGHT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectStaff"));
		$this->staffobject = $listbox;

		//--------------------------------------------------------------------------------------

		$this->doFit();

		// reselect any staffs previously selected
		if ($staffsubset)
			foreach ($staffsubset as $staffindex)
				$this->doSelectStaff($staffindex, true);
	}

	function isNextValid () {
		return in_array(true, $this->staffselected);
	}

	function updateGroup ($groupindex, $selected) {
		$this->groupselected[$groupindex] = $selected;

		if ($selected)
			$this->groupobject->SetSelection($groupindex);
		else
			$this->groupobject->Deselect($groupindex);
	}

	function doSelectGroup ($groupindex, $selected) {
		$this->updateGroup($groupindex, $selected);
		$this->checkSelectStaffs($groupindex, $selected);

		$this->updateNextButton();
	}

	function handleSelectGroup ($event) {
		$groupindex = $event->GetSelection();
		$selected = $this->groupobject->IsSelected($groupindex);

		$this->doSelectGroup($groupindex, $selected);
	}

	function checkSelectGroup ($staffindex) {
		$groupname = $this->SongData->StaffData[$staffindex]->HeaderValues["AddStaff"]["Group"];
		$groupindex = array_search($groupname, $this->grouplist);

		$selected = true;

		foreach ($this->SongData->StaffData as $staffindex => $StaffData)
			if ($StaffData->HeaderValues["AddStaff"]["Group"] == $groupname)
				if (!$this->staffselected[$staffindex])
					$selected = false;

		$this->updateGroup($groupindex, $selected);
	}

	function updateStaff ($staffindex, $selected) {
		$this->staffselected[$staffindex] = $selected;

		if ($selected)
			$this->staffobject->SetSelection($staffindex);
		else
			$this->staffobject->Deselect($staffindex);
	}

	function doSelectStaff ($staffindex, $selected) {
		$this->updateStaff($staffindex, $selected);
		$this->checkSelectGroup($staffindex);

		$this->updateNextButton();
	}

	function handleSelectStaff ($event) {
		$staffindex = $event->GetSelection();
		$selected = $this->staffobject->IsSelected($staffindex);

		$this->doSelectStaff($staffindex, $selected);
	}

	function checkSelectStaffs ($groupindex, $selected) {
		$groupname = $this->grouplist[$groupindex];

		foreach ($this->SongData->StaffData as $staffindex => $StaffData)
			if ($StaffData->HeaderValues["AddStaff"]["Group"] == $groupname)
				$this->updateStaff($staffindex, $selected);
	}

	function getInputData () {
		$staffsubset = array();

		foreach ($this->staffselected as $index => $value)
			if ($value)
				$staffsubset[] = $index;

		return $staffsubset;
	}
}

/*************************************************************************************************/

class toolPanel extends wizardPanel
{
	const prompt = "Select the user tool that you want to run on each selected staff part:";

	private $usertools = array();

	private $grouplist = array();
	private $toollist = array();

	private $groupobject = null;
	private $toolobject = null;

	private $groupselected = null;
	private $toolselected = null;

	function __construct ($parent, $nextButton, $usertools, $usertool) {
		parent::__construct($parent, $nextButton, self::prompt);

		$this->usertools = $usertools;
		$this->grouplist = array_keys($this->usertools);

		//--------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		//-------------------------------------------------
		// groups listbox

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 3);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Groups:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID());
		$colSizer->Add($listbox, 1, wxGROW|wxALIGN_LEFT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectGroup"));
		$this->groupobject = $listbox;

		//-------------------------------------------------
		// commands listbox

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 5, wxLEFT, 10);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Commands:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID());
		$colSizer->Add($listbox, 1, wxGROW|wxALIGN_RIGHT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectTool"));
		$this->toolobject = $listbox;

		//--------------------------------------------------------------------------------------

		$this->groupobject->Set($this->wxArrayString($this->grouplist, 20));

		$this->doFit();

		if ($usertool) {
			list($groupname, $toolname) = $usertool;

			if ($groupname)
				$this->doSelectGroup(array_search($groupname, $this->grouplist));

			if ($toolname)
				$this->doSelectTool(array_search($toolname, $this->toollist));
		}
	}

	function wxArrayString ($phpArrayString, $maxlen = -1) {
		$wxArrayString = new wxArrayString();

		foreach ($phpArrayString as $string) {
			if (($maxlen >= 0) && (strlen($string) > $maxlen))
				$string = substr($string, 0, $maxlen - 3)."...";

			$wxArrayString->Add($string);
		}

		return $wxArrayString;
	}

	function isNextValid () {
		return ($this->toolselected !== null);
	}

	function doSelectGroup ($groupindex) {
		if ($groupindex === $this->groupselected)
			return;

		$this->groupselected = $groupindex;
		$this->groupobject->SetSelection($this->groupselected);

		$groupname = $this->grouplist[$this->groupselected];

		$this->toollist = array_keys($this->usertools[$groupname]);
		$this->toolobject->Set($this->wxArrayString($this->toollist, 40));
		$this->toolselected = null;

		$this->doFit();

		$this->updateNextButton();
	}

	function handleSelectGroup ($event) {
		$groupindex = $this->groupobject->GetSelection();

		if ($groupindex >= 0)
			$this->doSelectGroup($groupindex);
	}

	function doSelectTool ($toolindex) {
		if ($toolindex === $this->toolselected)
			return;

		$this->toolselected = $toolindex;
		$this->toolobject->SetSelection($this->toolselected);

		$this->updateNextButton();
	}

	function handleSelectTool ($event) {
		$toolindex = $this->toolobject->GetSelection();

		if ($toolindex >= 0)
			$this->doSelectTool($toolindex);
	}

	function getInputData () {
		if ($this->groupselected !== null)
			$groupname = $this->grouplist[$this->groupselected];
		else
			$groupname = "";

		if ($this->toolselected !== null)
			$toolname = $this->toollist[$this->toolselected];
		else
			$toolname = "";

		return array($groupname, $toolname);
	}
}

/*************************************************************************************************/

class parmPanel extends wizardPanel
{
	const prompt = "Select all of the parameter values to specify to the user tool:";

	private $groupname = null;
	private $toolname = null;
	private $command = null;

	private $parmObjects = array();

	private static $selections = array();

	function __construct ($parent, $nextButton, $usertools, $usertool) {
		parent::__construct($parent, $nextButton, self::prompt);

		list($this->groupname, $this->toolname) = $usertool;
		$this->command = $usertools[$this->groupname][$this->toolname]["command"];

		if (!isset(self::$selections[$this->groupname][$this->toolname]))
			self::$selections[$this->groupname][$this->toolname] = array();

		//--------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		$col1Sizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($col1Sizer);

		$col2Sizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($col2Sizer, 0, wxLEFT, 10);

		preg_match_all('/<PROMPT:([^>]*)>/', $this->command, $m);

		foreach ($m[1] as $parm) {
			if (preg_match('/([^=]*)=(.*)$/', $parm, $m2)) {
				$statictext = new wxStaticText($this, $this->new_wxID(), $m2[1]);
				$col1Sizer->Add($statictext, 0, wxALIGN_LEFT|wxTOP, 15);

				$selection = array_shift(self::$selections[$this->groupname][$this->toolname]);

				switch ($m2[2][0]) {
					case "|":
						preg_match_all('/\|([^|]+)/', $m2[2], $m3);

						$parmObject = new wxChoice($this, $this->new_wxID(), wxDefaultPosition,
										wxDefaultSize, nwc2gui_wxArray($m3[1]));

						if ($selection)
							$parmObject->SetSelection(array_search($selection, $m3[1]));
						else
							$parmObject->SetSelection(0);

						break;

					case "#":
						preg_match('/\[(\d+),(\d+)\]/', $m2[2], $m3);

						// no "spin" stuff available right now :-(
						$range = range(intval($m3[1]), intval($m3[2]));

						if (count($range) <= 20) {
							$parmObject = new wxChoice($this, $this->new_wxID(), wxDefaultPosition,
											wxDefaultSize, nwc2gui_wxArray($range));

							if ($selection)
								$parmObject->SetSelection(array_search($selection, $range));
							else
								$parmObject->SetSelection(0);
						}
						else {
							$text = ($selection ? $selection : $m3[1]);
							$parmObject = new wxTextCtrl($this, $this->new_wxID(), $text);
						}
						break;

					case "*":
						$text = ($selection ? $selection : substr($m2[2], 1));
						$parmObject = new wxTextCtrl($this, $this->new_wxID(), $text);
						break;

					default:
						$this->parmObjects = array();
						$this->destroy();
						return;
				}

				$col2Sizer->Add($parmObject, 0, wxALIGN_LEFT|wxTOP, 10);
				$this->parmObjects[] = $parmObject;
			}
		}

		//--------------------------------------------------------------------------------------

		$this->doFit();

		// clear leftover selections, if any
		self::$selections[$this->groupname][$this->toolname] = array();
	}

	function getInputData () {
		$fullcommand = $this->command;
		self::$selections[$this->groupname][$this->toolname] = array();

		foreach ($this->parmObjects as $parmObject) {
			if (method_exists($parmObject, "GetString"))
				$selection = $parmObject->GetString($parmObject->GetSelection());
			else
				$selection = $parmObject->GetValue();

			$fullcommand = preg_replace('/<PROMPT:([^>]*)>/', $selection, $fullcommand, 1);
			self::$selections[$this->groupname][$this->toolname][] = $selection;
		}

		return $fullcommand;
	}
}

/*************************************************************************************************/

class verifyPanel extends wizardPanel
{
	const prompt = "Verify that the user tool command should be run against the staff parts:";

	function __construct ($parent, $nextButton, $SongData, $staffsubset, $fullcommand) {
		parent::__construct($parent, $nextButton, self::prompt);

		$staffnames = array();

		foreach ($staffsubset as $staffindex)
			$staffnames[] = $SongData->StaffData[$staffindex]->HeaderValues["AddStaff"]["Name"];

		//--------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		$statictext = new wxStaticText($this, $this->new_wxID(), "Command:  ");
		$rowSizer->Add($statictext);

		// wbn: would rather not wrap on a space within quotes?
		$statictext = new wxStaticText($this, $this->new_wxID(), strtr($fullcommand, " ", "\n"));
		$rowSizer->Add($statictext);

		//--------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		$statictext = new wxStaticText($this, $this->new_wxID(), "Staffs:  ");
		$rowSizer->Add($statictext);

		$statictext = new wxStaticText($this, $this->new_wxID(), $this->implodewithwrap(", ", $staffnames, 60, ",\n"));
		$rowSizer->Add($statictext);

		//--------------------------------------------------------------------------------------

		$this->doFit();
	}

	// join array elements with "glue", but wrap with "break" as needed
	function implodewithwrap ($glue, $pieces, $width = 75, $break = "\n") {
		$result = array();
		$line = array();

		foreach ($pieces as $element) {
			if ($line) {
				if (strlen(implode($glue, array_merge($line, array($element)))) > $width) {
					$result[] = implode($glue, $line);
					$line = array();
				}
			}

			$line[] = $element;
		}

		$result[] = implode($glue, $line);
		return implode($break, $result);
	}
}

/*************************************************************************************************/

class resultsPanel extends wizardPanel
{
	const prompt = "Results are shown below";

	private $text = array();
	private $textChoices;

	private $radiobuttonbaseid;
	private $textDisp;

	function __construct ($parent, $nextButton, $execResults, $panelHeight) {
		parent::__construct($parent, $nextButton, self::prompt);

		extract($execResults);

		$initialChoice = (($exitcode == NWC2RC_REPORT) ? "STDOUT" : "STDERR");

		$this->text["STDIN"] = $stdin;
		$this->text["STDOUT"] = $stdout;
		$this->text["STDERR"] = $stderr;

		$this->textChoices = array_keys($this->text);

		//-----------------------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		$radioSizer = new wxStaticBoxSizer(wxHORIZONTAL, $this);
		$rowSizer->Add($radioSizer);

		$this->radiobuttonbaseid = $this->cur_wxID() + 1;

		foreach (array_keys($this->text) as $textChoice) {
			$radioButton = new wxRadioButton($this, $this->new_wxID(), $textChoice);
			$radioSizer->Add($radioButton, 0, wxALL, 5);

			$this->Connect($this->cur_wxID(), wxEVT_COMMAND_RADIOBUTTON_SELECTED, array($this, "handleRadio"));

			if ($textChoice == $initialChoice)
				$radioButton->SetValue(true);
		}

		//-----------------------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		$textDisp = new wxTextCtrl($this, $this->new_wxID(), "", wxDefaultPosition, new wxSize(-1, $panelHeight - 85),
							wxTE_READONLY|wxTE_MULTILINE|wxTE_DONTWRAP);
		$rowSizer->Add($textDisp, 1);

		$this->textDisp = $textDisp;

		//-----------------------------------------------------------------------------------------------------

		$this->doFit();

		$this->displayChoice($initialChoice);
	}

	function displayChoice ($choice) {
		$this->textDisp->SetValue(implode("", $this->text[$choice]));
	}

	function handleRadio ($event) {
		$choice = $this->textChoices[$event->GetId() - $this->radiobuttonbaseid];
		$this->displayChoice($choice);
	}
}

/*************************************************************************************************/

class mainDialog extends wxDialog
{
	static $staffsubsets = array("all", "visible", "hidden", "audible", "muted");

	private $usertools = array();
	private $SongData = null;

	private $bitmap = null;

	private $dialogSizer = null;
	private $currentPanel = null;
	private $pagePanel = null;
	private $currentPage = null;

	private $statusText = null;
	private $backButton = null;
	private $nextButton = null;
	private $cancelButton = null;

	private $staffsubset = null;
	private $usertool = null;
	private $fullcommand = "";

	private $needsverify = false;
	private $parmsneeded = false;
	private $songchanged = false;

	function __construct () {
		parent::__construct(null, -1, "Run User Tool");

		$bmpfile = __DIR__.DIRECTORY_SEPARATOR."prw_RunUserTool.bmp";
		if (!file_exists($bmpfile))
			$this->fail("File missing: $bmpfile");

		$this->bitmap = new wxBitmap($bmpfile, wxBITMAP_TYPE_BMP);
		$this->bitmapHeight = $this->bitmap->GetHeight();

		$wxID = wxID_HIGHEST;

		$this->dialogSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($this->dialogSizer);

		//-----------------------------------------------------------------------------------------------------

		// first row is RUT logo and page panel

		$rowSizer = new wxBoxSizer(wxHORIZONTAL);
		$this->dialogSizer->Add($rowSizer, 0, wxGROW);

		$staticbitmap = new wxStaticBitmap($this, ++$wxID, $this->bitmap);
		$rowSizer->Add($staticbitmap);

		$this->pagePanel = new wxPanel($this, ++$wxID, wxDefaultPosition, new wxSize(RUT_PAGEWIDTH, -1));
		$rowSizer->Add($this->pagePanel, 0, wxGROW);

		//-----------------------------------------------------------------------------------------------------

		// second row is button set

		$rowSizer = new wxStaticBoxSizer(wxHORIZONTAL, $this);
		$this->dialogSizer->Add($rowSizer, 0, wxGROW);

		$this->statusText = new wxStaticText($this, ++$wxID, "");
		$rowSizer->Add($this->statusText, 0, wxALIGN_LEFT|wxALL, 5);

		$rowSizer->AddStretchSpacer();

		$this->backButton = new wxButton($this, ++$wxID, "< Back");
		$rowSizer->Add($this->backButton);
		$this->Connect($wxID, wxEVT_COMMAND_BUTTON_CLICKED, array($this, "DoBack"));

		$this->nextButton = new wxButton($this, ++$wxID, "Next >");
		$rowSizer->Add($this->nextButton, 0, wxRIGHT, 25);
		$this->Connect($wxID, wxEVT_COMMAND_BUTTON_CLICKED, array($this, "DoNext"));

		$this->cancelButton = new wxButton($this, wxID_CANCEL);
		$rowSizer->Add($this->cancelButton, 0, wxALIGN_RIGHT);
		$this->Connect(wxID_CANCEL, wxEVT_COMMAND_BUTTON_CLICKED, array($this, "DoCancel"));

		//-----------------------------------------------------------------------------------------------------

		$this->dialogSizer->Fit($this);

		$this->setupCtrlPanel("hidden", "hidden", "inactive", "Loading song file information...");
	}

	function fail ($msg) {
		fprintf(STDERR, "$msg\n");
		exit(NWC2RC_ERROR);
	}

	function setupCtrlPanel ($back, $next = "inactive", $cancel = "active", $status = null) {
		if ($status !== null)
			$this->statusText->SetLabel(str_pad($status, 80));

		$this->backButton->Show($back != "hidden");
		$this->backButton->Enable($back == "active");

		$this->nextButton->Show($next != "hidden");
		$this->nextButton->Enable($next == "active");

		$this->cancelButton->Show($cancel != "hidden");
		$this->cancelButton->Enable($cancel == "active");
	}

	function setupPage ($page) {
		switch ($page) {
			case "editstaffsubset":
				$this->setupCtrlPanel("hidden", "inactive", "active", "");
				$this->currentPanel = new staffPanel($this->pagePanel, $this->nextButton, $this->SongData, $this->staffsubset);
				break;

			case "editusertool":
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new toolPanel($this->pagePanel, $this->nextButton, $this->usertools, $this->usertool);
				break;

			case "editfullcommand":
				$this->setupCtrlPanel("active");
				$this->currentPanel = new parmPanel($this->pagePanel, $this->nextButton, $this->usertools, $this->usertool);
				break;

			case "editverification":
				$this->setupCtrlPanel("active");
				$this->currentPanel = new verifyPanel($this->pagePanel, $this->nextButton, $this->SongData, $this->staffsubset, $this->fullcommand);
				break;

			case "editresults":
				$this->setupCtrlPanel("inactive");
				$this->currentPanel = new resultsPanel($this->pagePanel, $this->nextButton, $this->execresults, $this->bitmapHeight);
				break;

			default:
				$this->fail("setupPage: unknown page: $page");
		}

		$this->needsverify = true;
		$this->currentPage = $page;
		$this->Refresh();
	}

	function teardownPage () {
		switch ($this->currentPage) {
			case "editstaffsubset":
				$this->staffsubset = $this->currentPanel->getInputData();
				break;

			case "editusertool":
				$this->usertool = $this->currentPanel->getInputData();
				break;

			case "editfullcommand":
				$this->fullcommand = $this->currentPanel->getInputData();
				break;

			case "editverification":
				break;

			case "editresults":
				break;

			default:
				$this->fail("teardownPage: unknown page: {$this->currentPage}");
		}

		$this->currentPanel->Destroy();
		$this->currentPanel = null;
	}

	function DoBack () {
		$this->teardownPage();

		switch ($this->currentPage) {
			case "editusertool":
				$this->gotoState("editstaffsubset");
				break;

			case "editfullcommand":
				$this->gotoState("editusertool");
				break;

			case "editverification":
				if ($this->parmsneeded)
					$this->gotoState("editfullcommand");
				else
					$this->gotoState("editusertool");

				break;

			default:
				$this->fail("DoBack: unknown state: {$this->currentPage}");
		}
	}

	function DoNext () {
		$this->teardownPage();

		switch ($this->currentPage) {
			case "editstaffsubset":
				$this->gotoState("getusertool");
				break;

			case "editusertool":
				$this->gotoState("getfullcommand");
				break;

			case "editfullcommand":
				$this->gotoState("getverification");
				break;

			case "editverification":
				$this->gotoState("getresultsbegin");
				break;

			case "editresults":
				$this->gotoState("getresultsnext");
				break;

			default:
				$this->fail("DoNext: unknown state: {$this->currentPage}");
		}
	}

	function DoCancel () {
		$this->Destroy();
	}

	function OnInit () {
		global $argv;

		array_shift($argv);

		$this->usertools = $this->getUserTools();
		$this->SongData = new ParseSong();

		$this->gotoState("getstaffsubset");
	}

	function gotoState ($nextstate) {
		global $argv;

		while (true) {
			switch ($nextstate) {
				case "getstaffsubset":
					// get staff subset(s) from args if any
					while ($argv && in_array(strtolower($argv[0]), self::$staffsubsets))
						$this->staffsubset = $this->mapStaffSubset(strtolower(array_shift($argv)));

					// ask for a staff subset, unless we have one from the args
					if ($this->staffsubset === null)
						$nextstate = "editstaffsubset";
					else
						$nextstate = "getusertool";

					break;

				case "editstaffsubset":
					$this->setupPage("editstaffsubset");
					return;

				case "getusertool":
					// get user tool from args if any, else get it from user
					if ($argv) {
						$this->usertool = $this->verifyUserTool(array_shift($argv));

						// run this user tool, but skip over it if no staffs selected 
						if ($this->staffsubset)
							$nextstate = "getfullcommand";
						else
							$nextstate = "getstaffsubset";
					}
					else {
						// ask for a user tool, but don't bother if no staffs selected
						if ($this->staffsubset)
							$nextstate = "editusertool";
						else
							$this->fail("Specified staff subset was empty");
					}

					break;

				case "editusertool":
					$this->setupPage("editusertool");
					return;

				case "getfullcommand":
					list($groupname, $toolname) = $this->usertool;
					$this->fullcommand = $this->usertools[$groupname][$toolname]["command"];
					$this->parmsneeded = preg_match('/<PROMPT:([^>]*)>/', $this->fullcommand);

					// ask for tool parameters, unless none are needed
					if ($this->parmsneeded)
						$nextstate = "editfullcommand";
					else
						$nextstate = "getverification";

					break;

				case "editfullcommand":
					$this->setupPage("editfullcommand");
					return;

				case "getverification":
					// ask for verification, unless not needed (command line mode)
					if ($this->needsverify)
						$nextstate = "editverification";
					else
						$nextstate = "getresultsbegin";

					break;

				case "editverification":
					$this->setupPage("editverification");
					return;

				case "getresultsbegin":
					$this->executeindex = 0;

					$nextstate = "getresults";
					break;

				case "getresults":
					$staffindex = $this->staffsubset[$this->executeindex];

					// get results and display them, unless they were applied
					if ($this->getResults($staffindex, $this->execresults))
						$nextstate = "editresults";
					else
						$nextstate = "getresultsnext";

					break;

				case "editresults":
					$this->setupPage("editresults");
					return;

				case "getresultsnext":
					$this->executeindex++;

					if ($this->executeindex < count($this->staffsubset))
						$nextstate = "getresults";
					else
						$nextstate = "getresultsdone";

					break;

				case "getresultsdone":
					// get staff subset(s) from args if any
					while ($argv && in_array(strtolower($argv[0]), self::$staffsubsets))
						$this->staffsubset = $this->mapStaffSubset(strtolower(array_shift($argv)));

					// if any args left, do another user tool, else done
					if ($argv)
						$nextstate = "getusertool";
					else
						$nextstate = "finished";

					break;

				case "finished":
					if ($this->songchanged)
						$this->SongData->OutputSongText(true);

					$this->Destroy();
					return;
			}
		}
	}

	function getResults ($staffindex, &$results) {
		$staffname = $this->SongData->StaffData[$staffindex]->HeaderValues["AddStaff"]["Name"];
		$this->setupCtrlPanel("inactive", "inactive", "inactive", "Processing: $staffname");

		list($groupname, $toolname) = $this->usertool;
		$compress = (bool)($this->usertools[$groupname][$toolname]["cmdflags"] & UTOOL_ACCEPTS_GZIP);

		$stdin = $this->SongData->GetClipText($staffindex);
		$exitcode = $this->runCommand($this->fullcommand, $stdin, $stdout, $stderr, $compress);

		if (($exitcode == NWC2RC_SUCCESS) & !$stderr) {
			if ($this->SongData->PutClipText($staffindex, $stdout))
				$this->songchanged = true;

			return false;
		}

		$results = compact("stdin", "stdout", "stderr", "exitcode");
		return true;
	}

	function mapStaffSubset ($subsetname) {
		$staffsubset = array();

		foreach ($this->SongData->StaffData as $index => $StaffData) {
			switch ($subsetname) {
				case "visible":
					if ($StaffData->HeaderValues["StaffProperties"][0]["Visible"] == "N")
						continue 2;
					break;

				case "hidden":
					if ($StaffData->HeaderValues["StaffProperties"][0]["Visible"] == "Y")
						continue 2;
					break;

				case "audible":
					if ($StaffData->HeaderValues["StaffProperties"][1]["Muted"] == "Y")
						continue 2;
					break;

				case "muted":
					if ($StaffData->HeaderValues["StaffProperties"][1]["Muted"] == "N")
						continue 2;
					break;
			}

			$staffsubset[] = $index;
		}

		return $staffsubset;
	}

	function getUserTools () {
		$ut = new NWC2UserTools();

		$usertools = array();

		// we want to allow user tools if and only if they neither expect nor return file text
		foreach (array_merge($ut->builtin, $ut->userdefined) as $groupname => $groupinfo)
			foreach ($groupinfo as $toolname => $toolinfo)
				if (!($toolinfo["cmdflags"] & (UTOOL_SEND_FILETXT|UTOOL_RETURNS_FILETXT)))
					$usertools[$groupname][$toolname] = $toolinfo;

		return $usertools;
	}

	function verifyUserTool ($toolname) {
		// check first if $toolname is just a tool name (no group specified)
		foreach ($this->usertools as $groupname => $grouptools)
			if (isset($grouptools[$toolname]))
				return array($groupname, $toolname);

		// check second if $toolname is a fully specified group and tool name
		$groupname = strtok($toolname, ":");
		$toolname = strtok("");
		if (isset($this->usertools[$groupname][$toolname]))
			return array($groupname, $toolname);

		$this->fail("RUT: Unrecognized user tool: $groupname:$toolname");
	}

	function runCommand ($command, $stdin, &$stdout, &$stderr, $compress = false) {
		$tempstdin = tempnam("", "rut");
		$tempstdout = tempnam("", "rut");
		$tempstderr = tempnam("", "rut");

		$prefix = ($compress ? "compress.zlib://" : "");
		file_put_contents($prefix.$tempstdin, $stdin);

		$redirs = array(array("file", $tempstdin, "r"),
				array("file", $tempstdout, "w"),
				array("file", $tempstderr, "w"));

		$process = proc_open($command, $redirs, $sink, null, null, array("bypass_shell" => true));
		$exitcode = proc_close($process);

		$stdout = gzfile($tempstdout);
		$stderr = gzfile($tempstderr);

		unlink($tempstdin);
		unlink($tempstdout);
		unlink($tempstderr);

		return $exitcode;
	}
}

/*************************************************************************************************/

class mainApp extends wxApp
{
	function OnInit () {
		$md = new mainDialog();
		$md->Show();

		$this->Yield();
		$md->OnInit();

		return 0;
	}

	function OnExit () {
		return 0;
	}
}

function runMain () {
	$ma = new mainApp();
	wxApp::SetInstance($ma);
	wxEntry();
}

runMain();
exit(NWC2RC_SUCCESS);

?>
