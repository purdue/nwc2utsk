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
	private $virtGroupStaffs = array();
	private $staffGroups = array();
	private $staffVirtGroups = array();

	private $grouplist = array();
	private $virtgrouplist = array();
	private $stafflist = array();

	private $groupobject = null;
	private $virtgroupobject = null;
	private $staffobject = null;

	private $groupselected = array();
	private $virtgroupselected = array();
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

		foreach ($virtGroupStaffs as $virtGroup => $virtStaffs) {
			$this->virtgrouplist[] = $virtGroup;
			$this->virtgroupselected[] = false;
			$this->virtGroupStaffs[] = $virtStaffs;

			$groupindex = count($this->virtgrouplist) - 1;

			foreach ($virtStaffs as $staffindex)
				$this->staffVirtGroups[$staffindex][] = $groupindex;
		}

		//--------------------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		//-------------------------------------------------
		// groups listboxes

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 3);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Groups:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, wxDefaultSize,
					 nwc2gui_wxArray($this->grouplist), wxLB_MULTIPLE);
		$colSizer->Add($listbox, 1, wxGROW|wxALIGN_LEFT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectGroup"));
		$this->groupobject = $listbox;

		if ($this->virtgrouplist) {
			$statictext = new wxStaticText($this, $this->new_wxID(), "Built-in Groups:");
			$colSizer->Add($statictext, 0, wxTOP, 10);

			$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, wxDefaultSize,
					 	nwc2gui_wxArray($this->virtgrouplist), wxLB_MULTIPLE);
			$colSizer->Add($listbox, 1, wxGROW|wxALIGN_LEFT);

			$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectVirtGroup"));
			$this->virtgroupobject = $listbox;
		}

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

	function updateVirtGroup ($groupindex, $selected) {
		$this->virtgroupselected[$groupindex] = $selected;

		if ($selected)
			$this->virtgroupobject->SetSelection($groupindex);
		else
			$this->virtgroupobject->Deselect($groupindex);
	}

	function doSelectVirtGroup ($groupindex, $selected) {
		foreach ($this->virtGroupStaffs[$groupindex] as $staffindex)
			$this->doSelectStaff($staffindex, $selected);
	}

	function handleSelectVirtGroup ($event) {
		$groupindex = $event->GetSelection();
		$selected = $this->virtgroupobject->IsSelected($groupindex);

		$this->doSelectVirtGroup($groupindex, $selected);
	}

	function checkSelectVirtGroups ($staffindex) {
		foreach ($this->staffVirtGroups[$staffindex] as $groupindex) {
			$selected = true;

			foreach ($this->virtGroupStaffs[$groupindex] as $staffindex2)
				if (!$this->staffselected[$staffindex2])
					$selected = false;

			$this->updateVirtGroup($groupindex, $selected);
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

		if ($this->staffVirtGroups)
			$this->checkSelectVirtGroups($staffindex);

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
					// get staff subset(s) from args if any
					while ($argv && in_array(strtolower($argv[0]), self::$staffsubsets))
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
					// get staff subset(s) from args if any
					while ($argv && in_array(strtolower($argv[0]), self::$staffsubsets))
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
	'eJztnA9UU1eawG+rOElICDr+oU67GmpoUUcTqQpqi0s5dERn6qADNWttZ0CSNrVC'.
	'0qLhz9h2GHamm+nQCmfGwjBWy1DLmJOcLXRRs9MRDu2ULB13z9LsYhFbWFRSQ07Y'.
	'k0w4Odnv3pfEkPcSKybXPS3f6dd7333v3d/9vvvdf48cc76/+KeIyIa5CD0I6SHQ'.
	'uXchdBfikfJhHULdSYxCIaNE4D6PB//hdCFCySkoOTkZCSHlLfg7NC/lfjRv6VqU'.
	'LPku4kmykCAtGyWn5yDBmnyUkpKCJBIJSk9PRynpciSXyyHNQinyHHT/+sdQVlYW'.
	'aD7KyclBCrinkGchRVYOKoZ3iiXpiCcvQIKMIpScpUC8rGJ0z5Yn0OItJWhNzh6U'.
	'k5+PCnLwuwXosceKUEGBAikUClSQXwz5YsgXo+Q8LXpwdyU804Cyf/IyKtinRruf'.
	'Lkc7lC+jQu2raIHiGEopbkbffea3SH7gbajn9/DuabTj2eNoe80ppIY2aLGmY5Wj'.
	'YjnWLFQM7VYThbZC29WgykIFKi5WIzWoUnkQqdVa9NLqdagqNxe9tiUXvfX330Na'.
	'aJ9WfRBVqdXoxD/sQ73Q/t78AtQLbe/HCm3uh/fNB8rQx/B+sboWFWtrkVqrR8oq'.
	'PdJCXgv5qqoq0FpQPdxvQMWHj6FnnjMgdW0D0v7it0ipb0Xq1ztQ9StvoFd/pkev'.
	'v9aMmpub4fkOdKShHekbWlF98yn0x7rXobwVnTjxLjp96hT6uOYVZH29EZkhP9T6'.
	'Dsqq7UU5+l6UrwZtHkMFejMqaB4C35qRQg3agLUfKZqxDoH9ZmhzLypuAIUyNTyj'.
	'hmut1gzaC3wzKE77UW0t3IP61M29SK//M6r75V9RA9TXAO82QJ0NDQNI2TqAqk72'.
	'oKfbRpDWOIzUHXZ0+H07qu0YQ7VmOzr2djdqbu1H+o5+sGMA6U8PoIYOSOGevteO'.
	'Wls70OnTHagD9HQHpKCtrWYoA+0wQ74fdACdOvXfUDaEWrt60ak//TtqHRhDp0GH'.
	'4L0x/D4829HRCzoECuVQzxhRKDdj7UVmSM0k7QcdAh1DvVA2BmonCu/3DiBzLzzT'.
	'2496Ie0l6QDoEOgYskOZHZf196N+ogOgY6B2NDAwADoEakdDkLfDfTvct0N+cmAQ'.
	'DQ0NgUIdn3+OXJC6xqCdQ3Y0NmZHPlC73Y5coD7QoSEXlLugzAXXLuRyQerC1z7I'.
	'+yAP6vOhpYiWFC+uNbwqfMlwjP8LgwGVrp5PSeSLWw0nRC8Zjos2Y662dCkVeWBJ'.
	'msHQuniToVW+iXD7tGspSFn2PSvaDG1p8jbDyRMMdzw3/rJDLk1c3GYwbAZnY6HE'.
	'zZNvastYBMifC35Jk/u05LjhZdE/GQxt/EfapnHz8rHk5d7I+zNMUX5uaHLLIodu'.
	'bU3ESJnsD9Pt3aZUKrczT+UVKpWlwMsrVZZuY0pKGXzhDMErMoAllYKjsxOOh/kZ'.
	'YIHH8pXK3ZDsDrYkX0mAeaSYLeNrFy5cuFR7Kaq9hmz+rw2G40k4G5WbB1bnBu4V'.
	'5pKG+O2eJs7VgSlpvtbJzd28Akw9KZJDB2evePsmXDDX71ZogRIDS4MlodiUkMlw'.
	'/qec3AoReLctQwAjt+34TewNMRdagHsWigsLWXVqp83Cc8q5uPemZgPsuCiDBDOL'.
	'W1hYuDuP4RbuLr3hVJwtJPhthSxHPxA2//+Ag7vtWSnA2jYnHefk4kguZQzbnb9b'.
	'GYgiYn5hHg7v3O0sR88J487p4wCvSQIXG/6wOQKXMe1G/24PuBnKtjPeZzk6nIvm'.
	'Odjc/DQ5niADbmZzC3HoMFzsbXwjj6R5hYUkuFiOns9aaUtzWVKexs8OQrntVeaF'.
	'cbczdhcylyxH/4jFXcDGbuCLhKHgcC7D8nsUwig30JTgsMoPt2ac7ejwUfydNQJZ'.
	'pliw+STbz3lQLcSzshQM3YbnycLSUuJSjAdg3nbAQwFchnv6PZbBVdMfuDdNIIPz'.
	'jowvyT7JtnfmsvomXLlAKssEcKaYtyT7BNjc+rPYrINV4Gr+gU2PJCZzcO/L4Euz'.
	'ZNjgLJlAvmSJKFWUtCRG6++nKevFQrFw8XPzgLp03jTuhkTGWoxNu2/b+pUbM+QP'.
	'xYibZ14ilMpkvKQDyWiHMzmEm/dMojRTKPVb+x1cVLp26fwYcTcsTpKB8O9e8iLU'.
	'FsLN+8m3oT2ZmQS7glkAYsYFm8QYK+MJ+OvzQ7l5ZYuWZ8lSibX8Fffmxpb7eJqI'.
	'YFPvFoukD4Zw8wHLdC1gpX5szLjfK+ZLCVfAk0r4mhCuLi1JJiZgWcKm+wLPx4p7'.
	'n1xIsOK5Ypn4LvkNbvlisSxTgmNKypPfeD7ILb9NEZPeTU0QyGTCuxNfLE+uIsWq'.
	'FSKZmIlkfkbI40Fu3+3JP4uWy2RSMY+MJKFg40ByFS5+XyrKzCKRLBVk/yXk+Vhx'.
	'308VyVL5CdhoqSBVIjpIuOfkgkBICbI/Dn0+VlzL83xxqkBC+hhMTlpUbDpX+5ac'.
	'H4zk6dgZcpvZcurZFQJRqiwgoiUH5Qn8u8kaRPr2L9NrmBm3jkv+8Xkpb3kQLBTy'.
	'JNLlfL6UYDe9H1ZDDLl1db86uEgkDVo8F+dFc8WZMkGGMryGmXOPNrKl6UWe5Iar'.
	'E/CQliQI+Gk/nhM77qCbLe2/kguCXJlEkErA3zq9AL0XM+5lH4h39BNG+ke9cNWl'.
	'f4YnCxcx/7XVqDy2XEej3i+NE5hb90pSajg3lf/r1SgltlxXix7n6/V19YOE25Qu'.
	'kkkl09jiRPNqtDC2XJ/jKGTfGOzS1/UTbj1spPhzE4TBsJZJ+Zv6YNN3KbZcd3sd'.
	'NtV7Vs9w6/7IEyXA6E0IDCgRP80UP67P1cT4ua4jIyFBlpUJ2w4MTpWK5z5njSPX'.
	'O+H2c89lzCVzlJAPvSxaLhUlPv8xihuXCOH2meVCskEXwQ4E7F0u5m9IiHlccXD7'.
	'3pGSTVwmH++4JBKZWCDcUBprbhOb23cINhkATk0gsSUVpiaJNp6LMbeRg9t3CE5D'.
	'eB8XXI8lorSDMVj3b8bt6zu4SCjhC7CxwuXEaglf/Pyhc/8Zb27fhkd4xNxUQQJf'.
	'JAG0dLlQlLb+mDkmXE8PzJP1PR42d77JnLGIAO8X83kJPL5AKBTwBUsOxIT7eT2Z'.
	'nj9hc2FfN/Tuj+UioRjY0uVikYj/7fXFb/nNvV3uBLMejXJyYbd37qFsWWKiUCQS'.
	'i0X89dYbNdx2/37S09OD115OrhP/peaBd49tfGjjxo3Phg6l2+UCzOunsrmXyGe8'.
	'1Vw1zJzbM8GS0ZZQrqOcfORJccaWW88h+hDufOY7x0JObGz3sViCXObrCre1cedG'.
	'sHaG3N9E4daHciNZO0NuQxRuXQh3zqWIVcSTOy9KFTPmHm1iS+N07o/iwe1ye1gy'.
	'Oo27IFoVM+f6ZylXT0tLz6ib5CemcSvjyb1MfKtvcbG48xxx5F6u90dTozucG613'.
	'b5c7wVgL06O+3R3GrYojt4vYenkQbzpG6XHxJrauDjYb+HB2Zjq3NHqNt8WdwL1b'.
	'DwtxC+5h7zTuTeS2uJcxpwU6Ftut91Dmen0eEtV0ufqzXh+Oq7qj9PyM+1ff4/Wd'.
	'1TN20+LieAaui4zifh81Lhm/Ta4zZMKaoMjF85W+CUeV/oyXItd3WR9pfo4v193D'.
	'zNBNE+HrUXy5zPrbcpm9/sabGyo0uI0T7M+xlylwI8rXkBvtvBBP7pvfMD9/E7ld'.
	'HH9OoTF+g/PVxI3vORTnSVc7/n519KybLtfR6O/Vdi9NrrslEE14m0WPSzZ0dV1d'.
	'HOeUeHK9LYyLvXhfOUiPS84pGEj5nDKK3dzkYrj1tM9HXp/3TpxTIEPspmjvqP+c'.
	'0kP8TY+LDyjAZY7fFM8pZBy1e8hxEB+/qc0b+HNO/ZmjZBR7KHK9PcF5cpTquuBq'.
	'Yj5g1V+mui6AxYMtR48e7WJWYKrnFLfL5aK47re4on+PjRdX3xj9+3O8uBHla8h9'.
	'8+EoEkeuel0UmeXGlPtCP1vObIk/93eBvzXj+crDXEw8TI3r+t0LarX6N4OUud53'.
	'mE59eJQy9xTDbXBR9rO7C2PVLtr965vA3EYPda4Dc89Qj2eG2+/7xnDdD98Brtfj'.
	'9XM9eMqixfV2NY26CNfd0jhIjzuhXvfCKF4L2tuhgCp33RtkmlRT5bobQtbdf6EY'.
	'V+03sFv6KXLdb2xhNnNbtrzppjl+R8+Okl9eDZ51URxHIeKlNW+oz/awpJ3C/mrd'.
	'Fg659f3k4a3LIkv4TyJiuI+9Je4LseXmFHAIF/d8NLl1bsGHF9mylYMbAwnlFtlh'.
	'LExeu3p9igyNSXw5lUOHO1m9a1fRRxh8vbp6kh53bCdwngLuVPXOH16jae+jywou'.
	'TPkma3KW/fAiPa7vQsGyw3/zTX0AgUyVO7Zr2eGpqQu7tt4J7lgRHj5UudeKlh2+'.
	'ug/T6HLtRcsKigiWOnerf66myr26izC3FuzLoR1XWAo+uLCTKvciWYEKjkxd3Lnz'.
	'Aj3u1BE8Ue6smfJ9sPNR+D8t7kU8YTxabfddL9q6ddcYNS5My8ty9kHmo0chuI5Q'.
	'417dV1RUDeuQ70vI7LtKL66mJieZZZ/JUOOGCQ3uzn0cQmF/BRMkW7j2k7HmftX9'.
	'81eWTyNKuZ9bGe3fBxs26iKL0RRR3rNaIokuyF0VWYbL9keWsiiiiXhH6efq0qP8'.
	'M2xWzFVxSZT24FcitzeUu6pEyZaVAa7OyJaKmHB/MO5gy44AdyTwV0C30xnIWvwG'.
	'W0dYMqzzcy3seyMmVSj3caf/k6PVVGmy+i+C3HFy6XGMWLq7rTbmtG/1cx3Tjv4e'.
	'/FXYXennjjBlTjf5IGKz4XotHFxPd8mqlekr1yitHjbXM6wrw/2qGfawuBZ/FBt1'.
	'FrjpCeU6OnUVOqPNZzNqNDobN9eyyh9Nay6xuY6KkpISFWgZaVUo16sLdp7GEcbt'.
	'xs+pLB6jCiecXIcyGMYqN4s7UlZSZjyvAbDOGW7viAZyFZ2dOpXqv8K4NpzR2Jwk'.
	'PrnttYK59+ywOjpXLV0zzOJaVWXDHo8JGz0SzsU9WvYFEDtVpjCurxMe7PR8gR8f'.
	'5u7f8zBwVpo8Pk95+ioLi2tRdXocFmxvCYvrMQLX5nN6xjXGcC4OfAsJf9JeNtdT'.
	'CZm1NnwvfaXJy/KzccRSgfuXi2sCrsOjG/EM2zi5bqO/7yNxd+COvbQy3ehhxbPD'.
	'VEaoJapx1jg6r9pfNjKO48bHxTVaIABUne6ZcH1WP7akkhVXmAuzNfQjN5fMqkZm'.
	'yN0q16nzYyvG2fPGeabyaFzVsHdGXBtjrqbTxp43vNjPlu6IXJMV/FwxM3vHMbfs'.
	'35wOo5HlZxxXGo+7zOrl5lpwwKvwXBYxnnGdn3Lai4dQpdvdqSqzcY0jDawVNtu4'.
	'NyJ3v4mb6yzHCyI0yqtLT9ex+7cSe/m8TlVSzuI6dWT8epw6nZOL2+2EIqYX2Fw8'.
	'bcCKaCPT9BorK56Hy/wTNCuevfgplcnabVRpwrkOHTxYYcIPkF5gc42rIJO+dsSn'.
	'wanOy1oHLWX7AQzTpTeM69aQyR8vV5XuMC5Zp0k8V0aIK6dJU16uAT+PaMvLtTaW'.
	'vT6vzWLsHLax49lz3r8J0Wms4f1rM+Ldik5XaWFWdc71F7YTBOB24zScCw94PIHs'.
	'9Ljy//raQdo0vX+dTrjhdDj9b4ZxbRy/4Q5yhzk2QecDXPZvopwB7hfse57p3Oj7'.
	'Ok0FWwL7tvPsDXK3xs81ce2e93/1fWw0ibDFVUXe/8aGG6k5N9/Hmh7/fmT5opNj'.
	'7xzcQ0eRQxHvBM5HR/ZGkQ+iHL0s/xpF/hTxTkWAu+eJyHJkpufByFL6/4CrqOGQ'.
	'PfHnPnV9Csvk5N9IYrf/LyR7KXDJ9+c/19QcuTjlu1pTXX1hyuejxJ2sxo598tr1'.
	'apxcp8jFgVR9/UOMr7ZT4/rGALTno8kawO29Ss/PPjsYrLgy+RR286SPNteuwG72'.
	'0Ob+B5lGPpyizH1iL1ztqcFupstlYtpHm7unBsJKcWSKNldxZQwun7TT517HcTVG'.
	'n3sNc6/Q5H72JPTvETxdKa5R5JKJ6glsLd1xNFmzVwE7KoVibw3dcTR55cqXX179'.
	'7LMvmb+qUOOGCwXuXq7fqygo7CcVHPJ13cdy2RqQOHL/56dR5K/x447HvupZ7ix3'.
	'ljvLneXOcme5s9xZ7ix3ljvLneXOcme5d5rL8ekqntIX4NIWLcOlL6WYeydk9f8B'.
	'b6PVww=='
	);

class logoBitmap extends wxBitmap
{
	function __construct () {
		$logofile = tempnam("", "rut");

		$logodata = gzuncompress(base64_decode(LOGODATA));
		file_put_contents($logofile, $logodata);

		$wxImage = new wxImage($logofile, wxBITMAP_TYPE_BMP);
		parent::__construct($wxImage);

		unlink($logofile);
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
