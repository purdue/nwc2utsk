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

Copyright � 2010 by Randy Williams
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

				if (!isset($this->HeaderValues[$ObjType]))
					$this->HeaderValues[$ObjType] = array();

				if ($ObjType == "Font")
					$this->HeaderValues[$ObjType][] = $o->Opts;
				else
					$this->HeaderValues[$ObjType] = array_merge($this->HeaderValues[$ObjType], $o->Opts);
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

		// must correct for possibility script sent back wrong EOL
		foreach ($clip->Items as &$item)
			$item = rtrim($item).PHP_EOL;

		if ($StaffData->BodyItems === $clip->Items)
			return;

		$StaffData->SaveBodyItems = $StaffData->BodyItems;
		$StaffData->BodyItems = $clip->Items;
	}

	function UndoClipText ($staffindex) {
		$StaffData =& $this->StaffData[$staffindex];

		if ($StaffData->SaveBodyItems) {
			$StaffData->BodyItems = $StaffData->SaveBodyItems;
			$StaffData->SaveBodyItems = array();
		}
	}

	function SongChanged () {
		foreach ($this->StaffData as $StaffData)
			if ($StaffData->SaveBodyItems)
				return true;

		return false;
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
	var $SaveBodyItems = array();

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

				if (!isset($this->HeaderValues[$ObjType]))
					$this->HeaderValues[$ObjType] = array();
				else if ($ObjType == "AddStaff")
					return false;

				$this->HeaderValues[$ObjType] = array_merge($this->HeaderValues[$ObjType], $o->Opts);
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

	private $groupStaffs = array();
	private $staffGroups = array();

	private $grouplist = array();
	private $stafflist = array();

	private $groupobject = null;
	private $staffobject = null;

	private $groupselected = array();
	private $staffselected = array();

	function __construct ($parent, $nextButton, $SongData, $staffsubset) {
		parent::__construct($parent, $nextButton, self::prompt);

		$this->SongData = $SongData;
		$virtGroupStaffs = array_fill_keys(array("all", "visible", "hidden", "audible", "hidden"), array());

		foreach ($this->SongData->StaffData as $staffindex => $StaffData) {
			$groupname = $StaffData->HeaderValues["AddStaff"]["Group"];
			$staffname = $StaffData->HeaderValues["AddStaff"]["Name"];

			if (!in_array($groupname, $this->grouplist)) {
				$this->grouplist[] = $groupname;
				$this->groupselected[] = false;
				$this->groupStaffs[] = array();
			}

			$this->stafflist[] = $staffname;
			$this->staffselected[] = false;
			$this->staffGroups[] = array();

			$groupindex = array_search($groupname, $this->grouplist);

			$this->groupStaffs[$groupindex][] = $staffindex;
			$this->staffGroups[$staffindex][] = $groupindex;

			$virtGroupStaffs["all"][] = $staffindex;

			if ($StaffData->HeaderValues["StaffProperties"]["Visible"] == "Y")
				$virtGroupStaffs["visible"][] = $staffindex;
			else
				$virtGroupStaffs["hidden"][] = $staffindex;

			if ($StaffData->HeaderValues["StaffProperties"]["Muted"] == "N")
				$virtGroupStaffs["audible"][] = $staffindex;
			else
				$virtGroupStaffs["muted"][] = $staffindex;
		}

		if (count($this->grouplist) <= 1)
			unset($virtGroupStaffs["all"]);

		foreach (array_keys($virtGroupStaffs) as $virtGroup) {
			if ($virtGroup == "all")
				continue;
	
			if (count($virtGroupStaffs[$virtGroup]) == count($this->stafflist))
				unset($virtGroupStaffs[$virtGroup]);
			else if (count($virtGroupStaffs[$virtGroup]) == 0)
				unset($virtGroupStaffs[$virtGroup]);
		}

		if ($virtGroupStaffs) {
			$this->grouplist[] = str_repeat("=", 10);
			$this->groupselected[] = false;
			$this->groupStaffs[] = array();
		}

		foreach ($virtGroupStaffs as $virtGroup => $virtStaffs) {
			$this->grouplist[] = $virtGroup;
			$this->groupselected[] = false;
			$this->groupStaffs[] = $virtStaffs;

			$groupindex = count($this->grouplist) - 1;

			foreach ($virtStaffs as $staffindex)
				$this->staffGroups[$staffindex][] = $groupindex;
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
		foreach ($this->groupStaffs[$groupindex] as $staffindex)
			$this->doSelectStaff($staffindex, $selected);
	}

	function handleSelectGroup ($event) {
		$groupindex = $event->GetSelection();
		$selected = $this->groupobject->IsSelected($groupindex);

		$this->doSelectGroup($groupindex, $selected);
	}

	function checkSelectGroups ($staffindex) {
		foreach ($this->staffGroups[$staffindex] as $groupindex) {
			$selected = true;

			foreach ($this->groupStaffs[$groupindex] as $staffindex2)
				if (!$this->staffselected[$staffindex2])
					$selected = false;

			$this->updateGroup($groupindex, $selected);
		}
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
		$this->checkSelectGroups($staffindex);

		$this->updateNextButton();
	}

	function handleSelectStaff ($event) {
		$staffindex = $event->GetSelection();
		$selected = $this->staffobject->IsSelected($staffindex);

		$this->doSelectStaff($staffindex, $selected);
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

	private static $selections = array();

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
		if (isset(self::$selections[$this->groupselected]))
			$this->doSelectTool(self::$selections[$this->groupselected]);

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

		self::$selections[$this->groupselected] = $toolindex;

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

class _wxChoice extends wxChoice
{
	function __construct ($parent, $id) {
		parent::__construct($parent, $id);
	}

	function GetValue () {
		// GetStringSelection not available at this time
		return $this->GetString($this->GetSelection());
	}

	function SetValue ($value) {
		// SetStringSelection not available at this time
		$this->SetSelection($this->FindString($value, true));
	}
}

class _wxSpinCtrl extends wxBoxSizer
{
	private $wxTextCtrl = null;
	private $wxSpinButton = null;

	function __construct ($parent, $id1, $id2) {
		parent::__construct(wxHORIZONTAL);

		$this->wxTextCtrl = new wxTextCtrl($parent, $id2);
		$this->Add($this->wxTextCtrl);
		$parent->Connect($id2, wxEVT_COMMAND_TEXT_UPDATED, array($this, "handleText"));

		$this->wxSpinButton = new wxSpinButton($parent, $id1, wxDefaultPosition, wxDefaultSize, wxSP_HORIZONTAL);
		$this->Add($this->wxSpinButton);
		$parent->Connect($id1, wxEVT_SCROLL_THUMBTRACK, array($this, "handleSpin"));
	}

	function handleSpin () {
		$this->wxTextCtrl->SetValue($this->wxSpinButton->GetValue());
	}

	function handleText () {
		$this->wxSpinButton->SetValue(intval($this->wxTextCtrl->GetValue()));
	}

	function GetValue () {
		return $this->wxSpinButton->GetValue();
	}

	function SetValue ($value) {
		$this->wxTextCtrl->SetValue($value);
	}

	function SetRange ($min, $max) {
		$this->wxSpinButton->SetRange($min, $max);
	}
}

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

						$parmObject = new _wxChoice($this, $this->new_wxID());
						$parmObject->Append(nwc2gui_wxArray($m3[1]));

						if (!$selection)
							$selection = reset($m3[1]);

						break;

					case "#":
						preg_match('/\[(\d+),(\d+)\]/', $m2[2], $m3);

						$parmObject = new _wxSpinCtrl($this, $this->new_wxID(), $this->new_wxID());
						$parmObject->SetRange($m3[1], $m3[2]);

						if (!$selection)
							$selection = $m3[1];

						break;

					case "*":
						$parmObject = new wxTextCtrl($this, $this->new_wxID());

						if (!$selection)
							$selection = substr($m2[2], 1);

						break;

					default:
						$this->parmObjects = array();
						$this->destroy();
						return;
				}

				$parmObject->SetValue($selection);

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

	function __construct ($parent, $nextButton, $SongData, $staffsubset, $fullcommand, $check) {
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

		$rowSizer = $this->newRow();

		$checkbox = new wxCheckBox($this, $this->new_wxID(), "Display summary of changes, if user tool returns success");
		$rowSizer->Add($checkbox);

		$checkbox->SetValue($check);
		$this->checkbox = $checkbox;

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

	function getInputData () {
		return $this->checkbox->GetValue();
	}
}

/*************************************************************************************************/

class textdelta {
	private $text1, $text2;
	private $lines1, $lines2;
	private $mode;
	private $report;

	public $oldlines = array();
	public $newlines = array();

	function __construct ($text1, $text2) {
		$this->text1 = array();
		$this->text2 = array();

		foreach ($text1 as $line) $this->text1[] = rtrim($line);
		foreach ($text2 as $line) $this->text2[] = rtrim($line);
	}

	function printsep ($mode) {
		static $cur_mode;

		if (isset($cur_mode) && ($mode != $cur_mode))
			$this->report[] = str_repeat("-", 79)."\n";

		$cur_mode = $mode;
	}

	function textequal ($linenum1, $linenum2) {
		return ($this->text1[$linenum1] === $this->text2[$linenum2]);
	}

	function printmatch ($linenum1, $linenum2) {
		if (($linenum1 !== null) && ($linenum2 !== null)) {
			$this->printsep(0);
			$this->report[] = sprintf("%5d %5d %s\n", $linenum1 + 1, $linenum2 + 1, $this->text1[$linenum1]);

			$this->lines1[$linenum1]++;
			$this->lines2[$linenum2]++;
		}
		elseif ($linenum1 !== null) {
			$this->printsep(1);
			$this->report[] = sprintf("%5d %5s %s\n", $linenum1 + 1, "-", $this->text1[$linenum1]);
			$this->oldlines[] = $this->text1[$linenum1];

			$this->lines1[$linenum1]++;
		}
		else {
			$this->printsep(2);
			$this->report[] = sprintf("%5s %5d %s\n", "-", $linenum2 + 1, $this->text2[$linenum2]);
			$this->newlines[] = $this->text2[$linenum2];

			$this->lines2[$linenum2]++;
		}
	}

	function findpivot ($min1, $max1, $min2, $max2) {
		$freq1 = array();
		$freq2 = array();

		for ($i=$min1; $i<=$max1; $i++) {
			$line = $this->text1[$i];

			if (!isset($freq1[$line]))
				$freq1[$line] = 0;

			$freq1[$line]++;
		}

		for ($i=$min2; $i<=$max2; $i++) {
			$line = $this->text2[$i];

			if (!isset($freq2[$line]))
				$freq2[$line] = 0;

			$freq2[$line]++;
		}

		$besti = null;

		for ($i=$min1; $i<=$max1; $i++) {
			$line = $this->text1[$i];

			if ($freq1[$line] == 1)
				if (isset($freq2[$line]) && ($freq2[$line] == 1))
					if (!$besti || (strlen($line) > strlen($this->text1[$besti])))
						$besti = $i;
		}

		if ($besti !== null) {
			$bestj = array_search($this->text1[$besti], array_slice($this->text2, $min2, $max2 - $min2 + 1, true));

			return array($besti, $bestj);
		}
		else
			return array(null, null);
	}

	function reportonlines ($min1, $max1, $min2, $max2) {
		if (($min1 > $max1) || ($min2 > $max2)) {
			for ($i=$min1; $i<=$max1; $i++)
				$this->printmatch($i, null);

			for ($i=$min2; $i<=$max2; $i++)
				$this->printmatch(null, $i);

			return;
		}

		while (($min1 < $max1) && ($min2 < $max2) && $this->textequal($min1, $min2)) {
			$this->printmatch($min1, $min2);

			$min1++;
			$min2++;
		}

		$buffer = array();

		while (($min1 < $max1) && ($min2 < $max2) && $this->textequal($max1, $max2)) {
			$buffer[] = array($max1, $max2);

			$max1--;
			$max2--;
		}

		list($pivot1, $pivot2) = $this->findpivot($min1, $max1, $min2, $max2);

		if ($pivot1 !== null) {
			$this->reportonlines($min1, $pivot1 - 1, $min2, $pivot2 - 1);

			$this->printmatch($pivot1, $pivot2);

			$this->reportonlines($pivot1 + 1, $max1, $pivot2 + 1, $max2);
		}
		else {
			for ($i=$min1; $i<=$max1; $i++)
				$this->printmatch($i, null);

			for ($i=$min2; $i<=$max2; $i++)
				$this->printmatch(null, $i);
		}

		while ($buffer) {
			list($linenum1, $linenum2) = array_pop($buffer);
			$this->printmatch($linenum1, $linenum2);
		}
	}

	function getreport () {
		$this->report = array();

		$this->lines1 = array_pad(array(), count($this->text1), 0);
		$this->lines2 = array_pad(array(), count($this->text2), 0);

		$this->reportonlines(0, count($this->text1) - 1, 0, count($this->text2) - 1);

		$buffer = array();
		$final_report = array();

		for ($i=0; $i<=count($this->report); $i++) {
			if (($i == count($this->report)) || ($this->report[$i][0] == "-")) {
				foreach ($buffer as $index => $line) {
					if (($index < 3) || ($index >= count($buffer) - 3))
						$final_report[] = $line;
					else if ($index == 3)
						$final_report[] = "...\n";
				}

				if ($i < count($this->report))
					$final_report[] = $this->report[$i];

				$buffer = array();
			}
			else
				$buffer[] = $this->report[$i];
		}

		return $final_report;
	}
}

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

		if (($exitcode == NWC2RC_SUCCESS) & !$stderr) {
			$td = new textdelta($stdin, $stdout);
			$delta = $td->getreport();

			$counts = array();

			foreach ($td->oldlines as $line)
				if ($objtype = NWC2GetObjType($line)) {
					if (!isset($counts[$objtype]))
						$counts[$objtype] = array("old" => 0, "new" => 0);
					$counts[$objtype]["old"]++;
				}

			foreach ($td->newlines as $line)
				if ($objtype = NWC2GetObjType($line)) {
					if (!isset($counts[$objtype]))
						$counts[$objtype] = array("old" => 0, "new" => 0);
					$counts[$objtype]["new"]++;
				}

			unset($counts["Fake"]);
			unset($counts["Context"]);

			foreach ($counts as $objtype => $diffs)
				if ($diffs["old"] == $diffs["new"])
					$stderr[] = "Changed {$diffs["old"]} $objtype objects\n";
				else if ($diffs["old"] && $diffs["new"])
					$stderr[] = "Changed {$diffs["old"]} $objtype objects into {$diffs["new"]} $objtype objects\n";
				else if ($diffs["old"])
					$stderr[] = "Removed {$diffs["old"]} $objtype objects\n";
				else
					$stderr[] = "Added {$diffs["new"]} $objtype objects\n";

			if (!$counts)
				$stderr[] = "Nothing added, removed, or changed\n";

			$stderr[] = "\n";
			$stderr = array_merge($stderr, $delta);
		}

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
	private $showchanges = false;

	function __construct () {
		parent::__construct(null, -1, "Run User Tool");

		$this->SetIcons(new iconBundle());

		$this->bitmap = new logoBitmap();
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

	function setupCtrlPanel ($back, $next, $cancel, $status) {
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
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new parmPanel($this->pagePanel, $this->nextButton, $this->usertools, $this->usertool);
				break;

			case "editverification":
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new verifyPanel($this->pagePanel, $this->nextButton, $this->SongData, $this->staffsubset, $this->fullcommand, $this->showchanges);
				break;

			case "editresults":
				$this->setupCtrlPanel("active", "inactive", "active", null);
				$this->currentPanel = new resultsPanel($this->pagePanel, $this->nextButton, $this->execresults, $this->bitmapHeight);
				break;

			default:
				$this->fail("setupPage: unknown page: $page");
		}

		if ($page != "editresults")
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
				$this->showchanges = $this->currentPanel->getInputData();
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

			case "editresults":
				$this->gotoState("getresultsback");
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

		if ($argv && (strtolower($argv[0]) == "display")) {
			$this->showchanges = true;
			array_shift($argv);
		}

		$this->usertools = $this->getUserTools();
		$this->SongData = new ParseSong();

		$this->gotoState("getstaffsubset");
	}

	function gotoState ($nextstate) {
		global $argv;

		static $GotArgUserToolAlready = false;
		static $ExecuteIndex = 0;

		while (true) {
			switch ($nextstate) {
				case "getstaffsubset":
					// get staff subset from args if any
					if ($argv && in_array(strtolower($argv[0]), self::$staffsubsets))
						$this->staffsubset = $this->mapStaffSubset(strtolower(array_shift($argv)));

					$GotArgUserToolAlready = false;
					$this->needsverify = false;

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
					if ($argv && !$GotArgUserToolAlready) {
						$this->usertool = $this->verifyUserTool(array_shift($argv));
						$GotArgUserToolAlready = true;

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
					$ExecuteIndex = 0;

					$nextstate = "getresults";
					break;

				case "getresults":
					$staffindex = $this->staffsubset[$ExecuteIndex];

					// get results and display them, unless skipping applied results
					if ($this->getResults($staffindex) || $this->showchanges)
						$nextstate = "editresults";
					else
						$nextstate = "getresultsnext";

					break;

				case "editresults":
					$this->setupPage("editresults");
					return;

				case "getresultsnext":
					$ExecuteIndex++;

					if ($ExecuteIndex < count($this->staffsubset))
						$nextstate = "getresults";
					else
						$nextstate = "getresultsdone";

					break;

				case "getresultsback":
					// undo this staff's results before backing up
					$staffindex = $this->staffsubset[$ExecuteIndex];
					$this->SongData->UndoClipText($staffindex);

					$ExecuteIndex--;

					if ($ExecuteIndex >= 0) {
						// undo previous staff's results before redoing them
						$staffindex = $this->staffsubset[$ExecuteIndex];
						$this->SongData->UndoClipText($staffindex);

						$this->showchanges = true;
						$nextstate = "getresults";
					}
					else
						$nextstate = "editverification";

					break;

				case "getresultsdone":
					// get staff subset from args if any
					if ($argv && in_array(strtolower($argv[0]), self::$staffsubsets))
						$this->staffsubset = $this->mapStaffSubset(strtolower(array_shift($argv)));

					$GotArgUserToolAlready = false;
					$this->needsverify = false;

					// if any args left, do another user tool, else done
					if ($argv)
						$nextstate = "getusertool";
					else
						$nextstate = "finished";

					break;

				case "finished":
					if ($this->SongData->SongChanged())
						$this->SongData->OutputSongText(true);

					$this->Destroy();
					return;
			}
		}
	}

	function getResults ($staffindex) {
		$staffname = $this->SongData->StaffData[$staffindex]->HeaderValues["AddStaff"]["Name"];
		$this->setupCtrlPanel("inactive", "inactive", "inactive", "Processing: $staffname");

		list($groupname, $toolname) = $this->usertool;
		$compress = (bool)($this->usertools[$groupname][$toolname]["cmdflags"] & UTOOL_ACCEPTS_GZIP);

		$stdin = $this->SongData->GetClipText($staffindex);
		$exitcode = $this->runCommand($this->fullcommand, $stdin, $stdout, $stderr, $compress);
		$this->execresults = compact("stdin", "stdout", "stderr", "exitcode");

		if (($exitcode == NWC2RC_SUCCESS) & !$stderr) {
			$this->SongData->PutClipText($staffindex, $stdout);
			return false;
		}

		return true;
	}

	function mapStaffSubset ($subsetname) {
		$staffsubset = array();

		foreach ($this->SongData->StaffData as $index => $StaffData) {
			switch ($subsetname) {
				case "visible":
					if ($StaffData->HeaderValues["StaffProperties"]["Visible"] == "N")
						continue 2;
					break;

				case "hidden":
					if ($StaffData->HeaderValues["StaffProperties"]["Visible"] == "Y")
						continue 2;
					break;

				case "audible":
					if ($StaffData->HeaderValues["StaffProperties"]["Muted"] == "Y")
						continue 2;
					break;

				case "muted":
					if ($StaffData->HeaderValues["StaffProperties"]["Muted"] == "N")
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

define("LOGODATA",
	'iVBORw0KGgoAAAANSUhEUgAAAHcAAAEECAMAAAAlJltjAAADAFBMVEV7e3ucnJxa'.
	'Wlq9vb0hISHe3t5CQkL///9jWlrW1t6pqb29vcaopd5aUsZSSsZKQsZjWs61tefG'.
	'xu9za8rn5/fv7/fWzu/i4u/W1u/Oxu/39/+Vkdjn3vdrY87b1vSCfcrOzu/Gve/3'.
	'9/e9tee1ree9vef37//v7/+1rd7Gvb3n1qXn1q2llIy1ra1za2trY2vW1ta9ra21'.
	'paWpnJCMg4qlnJxSQkLOxsaSgnyMc3OMe3uEe3taUlKEc3NXR0ecjpG9tbXGtbXn'.
	'3t737+9rY2PWzs7v5+fv7++qoqL/9/fe1tZvZ2djUlLGxsbOzs7Ovb1KOTnWxsa1'.
	'tbWRhqwpIWspIWOpqa0yLIBwbb1eW6IpKXMxKXNIQaFEPI5ERHu1e3uUQkKubGmf'.
	'R0fera3etbXWnJzGlJTv3t6tUlLv1tbnxsb35+e9c3PBjIzevb29e3vOpaXWpaXQ'.
	'iYbOlJTGe3vaqanOpZzDpJm9hIS9Wlq9Y2PGc3O9a2vnzs7nvb0YGBhva3MQEBAA'.
	'AAApKSkICAg5OTm9paUxMTFKSkLOtYzGnGuKc1ZrXlKZeGPGuaWpfUtzRhAtIRA5'.
	'IQg/JgpKLgojGAZxYFTvzs7OzsaEc2N6VChXMwpkPBZSMQgxKRiMe2fEo3vSv6mB'.
	'Yj85KRhjOQgeEggYEAAvGgZHNSOYiHOBZ0ygakxaORBZQi61kWuljGfGxr2cYxjG'.
	'vbVYPBlCKRCZWhIUCACUhGvWrYyYaS3Ga0rOnGOUc2PGlGPGa2MYEAzWtZSrlH+9'.
	'a1q9ta29lJQICAC9vbW9jFK1ta3Oxr0xMWNCOVoIAABCMRhCOWtKQlJCQms5MVo5'.
	'OWOMc2sxMVpCQmPkp2DvvYTvvYzvt3jnnEpaWnNra3taVmfgsHOkbTHntYTWpWfv'.
	'tWuEa2PGjELenFrenFLOlFrGhDnOnFq9lFoAEvtKsNwAAHYAAAAAAAAAAAAplAAY'.
	'AF0AAAAAAAD7yABAABIAAAAAAAD7rAAAABIAAAAAAAAAAAAAAAAAAACagmyBAAAA'.
	'AWJLR0SCi7P/RAAAAAlwSFlzAAAOxAAADsQBlSsOGwAADzFJREFUeF7t3I1fE+cB'.
	'B/AAwrkNHbWgFFDcdXZmI53EhIQgRnKScFRdV1FEpb5BEWjteLEdb6VSnba2cuRy'.
	'bg2hVhwJ1aCjgJ1r163rXLc4N8uq4ECte+32V+x5nruE5F6ChvD42cbv86kcucvz'.
	'5XnuebvApypVzINIrEoVE4c/qnnQjcedmATeJfAmbr7gTtcsUY5qzsWSORdP5lw8'.
	'mXPxZM7FkzkXT+ZcPJlz8WTOxZM5F0/mXDzxu1/6cph8ZbpS7j9+N3FBmCROV8r9'.
	'J+Au/KpyZtuVqysGN+mhRdIsmH334WSZD5qSMLkpixcvWZL6yCNL0rC6KelJC5KS'.
	'khYsSErPwOo+DDsS7GQL01MwusQjSwGZmA579zKcbnImEBcvgzVejN/NgO5y/O5y'.
	'8O3SZNzuwnTQuxYkpuF2UTKxjiPeTVqIfRyh+/s1tEw8lIbbXZwMl4NMEruLpq2l'.
	'KRjd5QBa+GgKnLCSUtOwuSmZfF9+CN7gzGSMLgSXLsuA/tIMbC6x7Ovp6YmL0ojU'.
	'9MzMFZjaOSMNJiXlMfQlOfkb4AsGd0G6TBbOvquY/0U3KUxWhilA/c0w+ZbimSzB'.
	'1Tz+beWsytYqJytMViue0QmuPidBOQZjbkQxKb8vLyquSSa5git3zhTirsnPk2at'.
	'3zXLtJb/51mnlqTALLga6Tm1PsR93EJJs97vFlqlWWfiXSspia1IcFdJz5FqU4hr'.
	'gxsM0maD6y5BUxT8GnADfwEA3uc/NPhd9Lrwk1ot8Dzpd4vhOZsNnLBZbcI7pa5N'.
	'Y9bpzGqSKI7R6WIsUpe2qLXZhRZUdohLrhM6uN5soEWuRauHJ/RFapuCq10DDnLm'.
	'FxNm+FVPi11SbczNzzeZjIW0yKXMfFcCvaaIErlqdAr8YyqyyrvrYD9KeMJCqNck'.
	'JGwwSOpbaMwHLPivyCZyaXiVSWMo0JrMNpFr1YMLszTwAtgWcu2sgx0btDOtz8nR'.
	'k2LXVgRI8zq9KV9nEbmETZ+ba7SA/qHXi11U4QLY1XKzSVmXLAIH82FdNq7N0Upc'.
	'ixlWlaKyTUaJS2rBYAN91WKJF99f5KrhBbmaMO56cH+ITXJuPGxm43dsVq1W3M4E'.
	'qYEuZUQtqeSa1BG5FuiCls6W9meCBqPZqC7gW1LG1RjAVJKl0K+mcW36fD5Z8RKX'.
	'gLMI6LRKLurPaBjcv0sYjAIs6c+8azSGdXO1kdWXtGoE2BQv5xqL401qQt7VquGU'.
	'nU0pu/NhX1XnrNXQYrdYW6zOMiG4WOzCfmW0kvpistAi268o2J/NVlkXTRxrQWcn'.
	'dTlr4E8e6qpN2aRVbZZ3tWj82sh4s1a+P6PWLpZ3DWCeenK9wZq9JmFDocQ1gAmS'.
	'JDWwnSUuBSTjKiBmmzRiN9sEZ4xV8PJCedeaF1juTZTELTbmG7XrYH31NrFbDG5f'.
	'blZ2tt5k+q54XYAHZosNftFbZF00MaNs2ERIXGuWMD0bDaTIpfW5/sB7GOIWoHUB'.
	'TBywodXyLlmQv2ZtztoNeahkcX8u1MMFx2QulM4bag0frR7OSaHrQrY+S6+1gPXQ'.
	'bFaqL7jKoCnSGIRvxOugtVhdUGCwoOEvGkdCaLQvoELuL03aKPgWymKB5Ya6T8TL'.
	'bGUCbrF/k0KBDUlgv8K7hmJJCvWCq5aeK9aEuOH3dXq5vXNu2ERlHyu/Hw2be3CL'.
	'5q9RTqHy++GMrByz4pkpN0wKwZZMMVqNYp4yyOydhR204G4u2aKcrWGey8Jmo2J0'.
	'wW6JTGbkKkcV5JZuk0nJ7LtlMr9PSduOyU1LSeF/k8If4HJTt5WVZcJfLCwBB9tS'.
	'sbnpwNm+DRw8ugP0s0Rs7qKd4GBHZjKRUVZSsnM5NjctsRRApelpxMrSHeBfXC6x'.
	'qByO2PLEtEWlpSvw9Sti+U40RZWvXFH69CKMbirvlpRv247VTS7bUiLM1bjd8rLt'.
	'+N1lZVs2p27bjt0F/Wpz2vKykgfipq2A0wdWd0X5ls2PEWkry/G6KZk7tpSvSCNS'.
	'wESN010Op8mH4QKYWfr0MnxuSubOnWWPwhU4IzMzBZ9LpCxLzeAX/pRkTPuNcrm/'.
	'V8Gwv9peLpMtMu66cJFlJAl2FSN2d+0OE1lGkojcPdFz7+vv3JC7VyYY3D37KiSp'.
	'3Dv77jN0yP4efVe1H5dbva+6CqZmXy2N0aWe3bsfZe/e5yiMbuVUN957AJ9LPT/l'.
	'7v4ePrcKHDwLzf3g4Bms7q5qOHYqK7G6dF19dS20DlANjTX4XIImaQq5BEniHEdw'.
	'KPEugXfeIAjr7v9D9yCN3a2CbiOJ26XqoLunFrNLv7Ab5flazO6LvLu/Gq9L1D6z'.
	'a8+ePd+vwdzO8M8PamtrSXz9edcBaQ5i2F8pZro385lz7yHP7Q+T6d7MJyL3+aYw'.
	'me7NfP7r3ObGekkaMbgNtdI/b6rG4Nb5n4/gfOVfE/G5dE1DS0tLXTWN162tb0Wd'.
	'qbWNxunSFf5e3FyN020DtW092AK5ShKfSzcgsKYZtTQ+txYM2OYKmqqH3gF8bnUr'.
	'cokKWOF6GpvbBjsUOKhGDY3XbWqgCRoNJhKbi+pZD7awDXjrWwXr2VzDu434XDSO'.
	'mipp2M6Qx+US+2BDN9XVgS+t1RhdClUYBg4nfC5hbRTcSqzrApiyKptBWvZReNdB'.
	'iFVUVONc92X+NrwNg6uY6d7MZ869hzz3gNzvPyD3QbXzg3Qbqx7M+A3MV0HBMl8J'.
	'bm1FQ0NDG4XZpSrQgtRcX4XXbWsWelMjhdOtght39GjWfBDnul/XBPeTB1GFq/C5'.
	'8AEFbHBq0T3G+JxSJTynoO1dA759LHpO2UcT6IGwBa8L60lifk7hXTCC4INoMz4X'.
	'PafAB27Mzyn8A/dLBAE/aTgY2p9V4UuckYvGb1NTW43Mc0p7+D9ymZlbxc/OgG2u'.
	'pDC66AMdFMn83P5y2CJm6BJtfI0basXrUfsha7giZuqi9beiWrr+tncUhSsicpcK'.
	'/3lse8cr4YqI2G2Rfvwc8vlze0dHuDscsasY4RrgHgpTxGy6HYc3KRYRkRvueaFV'.
	'uAa6HUdsSkVE5MaFcX8gXILcjqNK8Cy7ijWO3G2VSXOQOy9sjSN3K6okqW4Icrda'.
	'dYeVaxy528bPVwRNB55Y6LpgNy5u0zEIx8qVMFOXeqli6vMcsRtnSwDusVdfO/76'.
	'8ePH3zgRVMIM3apG+PEV+qWGnKs+8Xon09VlZ1mHg+VOGqZKmKH7Q7QOtr4k6/7o'.
	'1TedrN3R7WJcPQ6W5d46eert08LpGbok/Ii/tYKUuvM0p3vPsMBkfuzg+tx9nMdu'.
	'93Ce/nf40zN0CQqsBa38XzOEumfP9bm7GYbxetwc4l09dnbg5GtChWfLPX/G3s15'.
	'gGa39wCUYbo5x4XVJ37iL2F23NVOj2twkEEVBjZQ2YHz7waXMGO3XsZdPcQyg4OD'.
	'XjeqqcvuHWaPBw+iuCi4lVL3RZcXqIMjHAvbt5txeOxnVaISZsE97bSPQJblXIzX'.
	'5ehxcGfdHUdFJUTLpasowT3RexHc20HGznkZhu1xsV0X3gWzlmgLEC23tr5GcHvd'.
	'bmZwhOnzwJsL6nvxPUNc7Gy59L7mA7z70z7WzTB2N4v6FKgxN6CZBdcKH8meralr'.
	'buLd1l7OxV102wUVdmfuUvTd2gb0QRJY8Fv5dq7PYRlXt5cJiqPrdGy0+5WVX49A'.
	'4Oc5wP3ZcIgJ4+Xej+04Iiphhi5d/RKfA3ANpuuaP+gTs4yD+3lsh05UQuRujfTz'.
	'WKryQ6dnCuz2wLp3u3/xy1c6nhKVELnb0ihN/Ud93QGWddsR6+EG3jwsLiFyVyYf'.
	'nj/DBjoyexEesxcdI4ynN09cQhTdX11w9fUEamu393W7ejgOzV3cpY9FJUTm/lqa'.
	'F94Y8rBTfZntP+90c5c9cF2CcO9vQkuIzJVGfYFzeD2B9Xb4zCnNiU/ednIjgwLc'.
	'GbL8Rs392MuCgep2QNbj7WbPo/3kCafHD3s6o7DuS/NbFtxal6MPTpB9ds/x3/Gf'.
	'53zsYkcGR6Dt8nQGN3XA1c0wDlhVxusG49d+uesjXftW9LIJbD0cLuEeB10ecH0z'.
	'yxWnHd1bx0UH4/i90+dr38qf0F11MCPdUHb1Oaeuj5b7h1McP3I9fa5uzjzl+vQD'.
	'w4wD3WXGfemK//poub7HB1jkei87WNcfg9xrxjM9gV7t+lR4NWru6Add6A6DjQZ3'.
	'8lqQ6xsF8CDj5eEhAY6a6/OdvToMXe5y/0fxwa5v9E9vgXuAejXjGcpDr0XRHT3d'.
	'j0bR8DvtHettQS5sC9eIne/VHudn8KUouhuPnHTYHfar7x0C28eEQ0EuaIsuFzMi'.
	'TCADV66fXHu81/l6dNythzs6uHcunesSPlEJca+Abdcgwwg17u9nvexwf1Tc2A5R'.
	'QlyfDzwxoRqPOPr6O2+MjY/f/HPAHZ3Iy8ubnMxTTfh81yfz8lSTKtXkKDwBvsm7'.
	'5hu9pQLnR+G36OWgPCVmxe6nA2htYrjuztvjfILqC8oHIw4Ujv6dQOB1eAKAUJqA'.
	'PDinCi3UF39YzB6yiS75bIOHGXF47tweG1dweQS5ws/g893Ky7vFn4ff3romKvRl'.
	'SXVf8YmjO8ux9s6AKuNOwlqFuqPo6yi4CbDe4lb2zZO44hYB0Q1wwaxcfVX++k7w'.
	'9UQH4LVbQuuLi5Q2s1V8CajDgPMm4JTaGdxT1TX+/l6bQDh6EzyaHB0FP5O0mSXu'.
	'4TjxFSAbhm8A7fM7d2XdycnJCdiMsIUnUM35wMNJVO/rkmb2HRO5T4gvALn+hgvW'.
	'9s6wvOu/jG9RMJiE7wE4yTfDpKSZfTGhtdVJLgD51NsJsLts79j0rn/g+FDPQkNJ'.
	'FXglKLYjQey8jZLzMFksqOhYrwc09tjdsWncoApP8j17Yqrpg2KLDagx4pEr5M4Q'.
	'6FW3WSdgO4f+Mo071aHBC6ieo/6eJkr8/KNHjybEbJI9CeN0gkp2cu+Dth52htb3'.
	'el5AgTMVrBjswNf5V4R6Tkqb+Z4y1AsslwtUutN9N+T+jl6D4Uvnj4UD/iXBi5BF'.
	'9b3ZdQ7UlGE+F/Wr2cxfu++O/439O7i93LkxjO6o89JY7xnQzP/w/FM8jmY1652u'.
	'rqugpneu3gy4MeH+P2PRirHzyaGx8bEBcKNv30CuKsz/zS2KOdY/ADYaVy+N33Re'.
	'gm7sPExxgia+wX4BZss7wE3owJVTVz8Z/5f9i/HXuH+Pj/8HjKdcXpyelF8AAAAA'.
	'SUVORK5CYII='
	);

class logoBitmap extends wxBitmap
{
	function __construct () {
		$wxStream = new nwc2gui_MemoryInStream(LOGODATA, array("base64"));

		$wxImage = new wxImage($wxStream, wxBITMAP_TYPE_PNG);
		parent::__construct($wxImage);
	}
}

/*************************************************************************************************/

// Embedded file code created by nwswEncodeFile.php
define('MSTREAM_RUTICOBYERIC_ICO',
	'eJztVr1u3DgQHkYHWDBwCAkW2c4ur6RA4HY7u9jer5HSxhZWZxkCTur8Bvcsfpwr19gFpI75hpRW1JoKguRSJdRK2uE3fxyOhkMkKKPbW0k8Pl8S/Y339XWg'.
	'//tA9C/mpAz0X4Lonz/xxv9b3BVuQX94jC5pYVSBsarCiy+ewuP19RUzzv/IufDii6eYTo993lab1rYvS/iqrVZts4T3BvJ52y7hFeVtfbGI9xmE62zAt+He'.
	'bvmPp/YrFhYTftzit93eDbjxoNmccCBH/zvhBHw14sftyYq/lReuLkacFcc45O+Dgx73np3hzwCpPOF3MxyhrdnBMvI/xt/y4H28/hg/QLkP7wLugNdfw+Fg'.
	'G+s/s88OhnHu/xDlQ36Guzkuswh/P94Ex34ZN9qIchk/CGsHC0l8n1lrKV/EjdXWFmTbtrlhdUQzNsPi1nzs8WRAiuIqgnsqGKebkYY3WYRXmYF6c5qqQNFV'.
	'JG5n4h3TNCnYn4uzN2LysLIFZswozrEAt7w5iQfnZ7GQWo4TUmaxdXevmbTyIpBvAnGJrDsTQiXFSOqZuJOsXlgaComw2UyccRLQUHgR49ceR4u32vKScl4M'.
	'zZ1n/0WhQ8Be3CbsTGQd4ZQhnlauOiJt59aZgfVbXoRRJM7FmYGCASg6W3scA2bIeKPew/BxsMBKPr7HD6OLGjwJ3A2450nhZsKL78H3vEVhmBR+0ERZwOkp'.
	'gfcCaeGDQCIBYwHChn1ILs/jnAZWyTyJVxTCBzPpiuC/Ao5/kYi/Gz4DNoA8SDIYn+awIs73f1DAynmJyMekC9j9MVGSa8Am8wKYRX5Ku8CJTqTwlGKTYKDA'.
	'4Ecqzp2Pse8TdNLHviDvwEIQMXaQVTI69Zc6it/j1xjcZ15T6DXHPrP6sMRd/R99Zp1XbRvR67wuI7or182mdUdc3LaB3rW5p49+sisrNKb4cxfoftOgmUDn'.
	'MdDukVubid/V3AhE9H3ZnvjRr3SaG9HJPg5Ylu8fw0fEXUDelG6vbgbYmidMq3CM9agbfHY+61C6GnzC/H6whs/kXmUedtpKrhud8tJoMXEmo0xA+VVQq1jv'.
	'Tg+w63EwFxmKzlhLenQdSsipOHFfoYopsG++Oukpjopr4lSKesnnT1S7uGDJq4lutNUqLixcj01Uyw4aJ7mKKtdOFFKpx6fTxIPi+lVOHDWqjYd/WgJ/4+A8'.
	'58p5TVOevy5yVz+W5/1jyKdmHULRbELQuibMd0O+79ZttwZpN+5Bvrgd2k2lnnqQiOSKSaRfofyJ9qy136u9KnxAezvsRK1UsFLLi5cfjvMXdSxD5Q=='
	);

class iconBundle extends nwc2gui_IconBundle
{
	function __construct () {
		$wxStream = new nwc2gui_MemoryInStream(MSTREAM_RUTICOBYERIC_ICO, array("base64", "gz"));

		parent::__construct($wxStream);
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
