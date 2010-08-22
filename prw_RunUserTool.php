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
require_once("usr/nwc2parse.inc");
require_once("usr/nwc2staffs.inc");

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

// prompt the user for a particular user tool
class toolDialog extends wxDialog
{
	private $usertools = array();

	private $grouplist = array();
	private $namelist = array();

	private $groupobject = null;
	private $nameobject = null;

	private $groupselected = null;
	private $nameselected = null;

	function __construct ($usertools) {
		$this->usertools = $usertools;

		$this->grouplist = array_keys($this->usertools);
		$this->namelist = array(); // empty until group selected!

		//-------------------------------------------------------------------------------------

		parent::__construct(null, -1, "RUT: Select user tool");

		$dialogSizer = new wxBoxSizer(wxHORIZONTAL);
		$this->SetSizer($dialogSizer);

		$wxID = wxID_HIGHEST;

		//--------------------------------------------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer, 0, wxGROW);

		$statictext = new wxStaticText($this, ++$wxID, "Groups:");
		$colSizer->Add($statictext, 0, wxTOP|wxLEFT, 10);

		$listbox = new wxListBox($this, ++$wxID, wxDefaultPosition, wxDefaultSize,
						nwc2gui_wxArray($this->grouplist), wxLB_SINGLE|wxLB_HSCROLL);
		$colSizer->Add($listbox, 0, wxGROW|wxALL, 10);

		$this->Connect($wxID, wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "doSelectGroup"));
		$this->groupobject = $listbox;

		//--------------------------------------------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer, 0, wxGROW);

		$statictext = new wxStaticText($this, ++$wxID, "Commands:");
		$colSizer->Add($statictext, 0, wxTOP|wxLEFT, 10);

		$listbox = new wxListBox($this, ++$wxID, wxDefaultPosition, new wxSize(300,200),
						nwc2gui_wxArray($this->namelist), wxLB_SINGLE|wxLB_HSCROLL);
		$colSizer->Add($listbox, 0, wxGROW|wxALL, 10);

		$this->Connect($wxID, wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "doSelectCommand"));
		$this->nameobject = $listbox;

		//-------------------------------------------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer);

		$button = new wxButton($this, wxID_OK);
		$colSizer->Add($button, 0, wxALL, 10);

		$button = new wxButton($this, wxID_CANCEL);
		$colSizer->Add($button, 0, wxALL, 10);

		//-------------------------------------------------------------------------------------

		$dialogSizer->Fit($this);
	}

	function doSelectGroup ($event) {
		$this->groupselected = $event->GetSelection();
		$this->nameselected = null;

		$this->namelist = array_keys($this->usertools[$this->grouplist[$this->groupselected]]);
		$this->nameobject->Set(nwc2gui_wxArray($this->namelist));
	}

	function doSelectCommand ($event) {
		$this->nameselected = $event->GetSelection();
	}

	function getUserTool () {
		return array($this->grouplist[$this->groupselected], $this->namelist[$this->nameselected]);
	}
}

/*************************************************************************************************/

// prompt the user for all user tool parameters
class parmDialog extends wxDialog
{
	private $groupname = null;
	private $toolname = null;
	private $command = null;

	private $parmObjects = array();

	private static $selections = array();

	function __construct ($groupname, $toolname, $command) {
		$this->groupname = $groupname;
		$this->toolname = $toolname;
		$this->command = $command;

		if (!isset(self::$selections[$this->groupname][$this->toolname]))
			self::$selections[$this->groupname][$this->toolname] = array();

		//------------------------------------------------

		parent::__construct(null, -1, "RUT: $toolname");

		$dialogSizer = new wxBoxSizer(wxHORIZONTAL);
		$this->SetSizer($dialogSizer);

		$wxID = wxID_HIGHEST;

		//------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer);

		preg_match_all('/<PROMPT:([^>]*)>/', $this->command, $m);

		foreach ($m[1] as $parm) {
			if (preg_match('/([^=]*)=(.*)$/', $parm, $m2)) {
				$statictext = new wxStaticText($this, ++$wxID, $m2[1]);
				$colSizer->Add($statictext, 0, wxALL, 10);

				$selection = array_shift(self::$selections[$this->groupname][$this->toolname]);

				switch ($m2[2][0]) {
					case "|":
						preg_match_all('/\|([^|]+)/', $m2[2], $m3);

						$parmObject = new wxChoice($this, ++$wxID, wxDefaultPosition,
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
							$parmObject = new wxChoice($this, ++$wxID, wxDefaultPosition,
											wxDefaultSize, nwc2gui_wxArray($range));

							if ($selection)
								$parmObject->SetSelection(array_search($selection, $range));
							else
								$parmObject->SetSelection(0);
						}
						else {
							if ($selection)
								$parmObject = new wxTextCtrl($this, ++$wxID, $selection);
							else
								$parmObject = new wxTextCtrl($this, ++$wxID, $m3[1]);
						}

						break;

					case "*":
						if ($selection)
							$parmObject = new wxTextCtrl($this, ++$wxID, $selection);
						else
							$parmObject = new wxTextCtrl($this, ++$wxID, substr($m2[2], 1));

						break;

					default:
						$this->parmObjects = array();
						$this->destroy();
						return;
				}

				$colSizer->Add($parmObject, 0, wxALL, 10);
				$this->parmObjects[] = $parmObject;
			}
		}

		self::$selections[$this->groupname][$this->toolname] = array();		

		//------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer);

		$button = new wxButton($this, wxID_OK);
		$colSizer->Add($button, 0, wxALL, 10);

		$button = new wxButton($this, wxID_CANCEL);
		$colSizer->Add($button, 0, wxALL, 10);

		//------------------------------------------------

		$dialogSizer->Fit($this);
	}

	function getFullCommand () {
		$command = $this->command;
		self::$selections[$this->groupname][$this->toolname] = array();

		foreach ($this->parmObjects as $parmObject) {
			if (method_exists($parmObject, "GetString"))
				$selection = $parmObject->GetString($parmObject->GetSelection());
			else
				$selection = $parmObject->GetValue();

			$command = preg_replace('/<PROMPT:([^>]*)>/', $selection, $command, 1);
			self::$selections[$this->groupname][$this->toolname][] = $selection;
		}

		return $command;
	}
}

/*************************************************************************************************/

// show the user the results of a user tool
class resultsDialog extends wxDialog
{
	private $textDisp;
	private $text = array();

	private $radiobuttonbaseid;
	private $textChoices;

	function __construct ($staffname, $stdin, $stdout, $stderr, $initialChoice) {
		$this->text["STDIN"] = $stdin;
		$this->text["STDOUT"] = $stdout;
		$this->text["STDERR"] = $stderr;

		$this->textChoices = array_keys($this->text);

		//-----------------------------------------------------------------------------------------------------

		parent::__construct(null, -1, "RUT: $staffname");

		$dialogSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($dialogSizer);

		$wxID = wxID_HIGHEST;

		//-----------------------------------------------------------------------------------------------------

		$rowSizer = new wxBoxSizer(wxHORIZONTAL);
		$dialogSizer->Add($rowSizer, 0, wxGROW);

		$statictext = new wxStaticText($this, ++$wxID, "Results are shown below");
		$rowSizer->Add($statictext, 0, wxALL, 10);

		$rowSizer->AddStretchSpacer();

		$button = new wxButton($this, wxID_OK);
		$rowSizer->Add($button, 0, wxALL, 10);

		//-----------------------------------------------------------------------------------------------------

		$rowSizer = new wxBoxSizer(wxHORIZONTAL);
		$dialogSizer->Add($rowSizer, 0, wxGROW);

		$radioSizer = new wxStaticBoxSizer(wxHORIZONTAL, $this);
		$rowSizer->Add($radioSizer, 0, wxALL, 10);

		$this->radiobuttonbaseid = $wxID + 1;

		foreach (array_keys($this->text) as $textChoice) {
			$radioButton = new wxRadioButton($this, ++$wxID, $textChoice);
			$radioSizer->Add($radioButton, 0, wxALL, 10);
			$this->Connect($wxID, wxEVT_COMMAND_RADIOBUTTON_SELECTED, array($this, "doRadio"));

			if ($textChoice == $initialChoice)
				$radioButton->SetValue(true);
		}

		$rowSizer->AddStretchSpacer();

		$button = new wxButton($this, wxID_CANCEL);
		$rowSizer->Add($button, 0, wxALL, 10);

		//-----------------------------------------------------------------------------------------------------

		$this->textDisp = new wxTextCtrl($this, ++$wxID, "", wxDefaultPosition, wxDefaultSize,
							wxTE_READONLY|wxTE_MULTILINE|wxTE_DONTWRAP);
		$dialogSizer->Add($this->textDisp, 1, wxEXPAND|wxALL, 10);

		$this->displayChoice($initialChoice);

		//-----------------------------------------------------------------------------------------------------

		// if text for initialChoice is small, text area will be too small if we Fit!
		//$dialogSizer->Fit($this);
	}

	function displayChoice ($choice) {
		$this->textDisp->SetValue(implode("", $this->text[$choice]));
	}

	function doRadio ($event) {
		$choice = $this->textChoices[$event->GetId() - $this->radiobuttonbaseid];
		$this->displayChoice($choice);
	}
}

/*************************************************************************************************/

class mainApp extends wxApp
{
	private $usertools = array();
	private $SongData = null;

	function OnInit () {
		global $argv;

		array_shift($argv);

		$this->usertools = $this->getUserTools();
		$this->SongData = new ParseSong();

		$staffsubsets = array("all", "visible", "hidden", "audible", "muted");
		$staffsubset = null;

		$songchanged = false;
		$usertoolgiven = false;

		// if no args, or the first arg is not a staff subset, prompt for one
		if (!$argv || !in_array(strtolower($argv[0]), $staffsubsets)) {
			$sd = new staffDialog($this->SongData);

			if ($sd->ShowModal() == wxID_CANCEL)
				exit(NWC2RC_SUCCESS);

			$staffsubset = $sd->getStaffSubset();

			if (!$staffsubset)
				exit(NWC2RC_SUCCESS);
		}

		foreach ($argv as $arg) {
			if (in_array(strtolower($arg), $staffsubsets)) {
				$staffsubset = $this->getstaffsubset(strtolower($arg));
				continue;
			}

			list($groupname, $toolname) = $this->verifyUserTool($arg);

			if ($this->executeall($staffsubset, $groupname, $toolname))
				$songchanged = true;

			$usertoolgiven = true;
		}

		// if no args, or none of the args was a user tool, prompt for one
		if (!$usertoolgiven && $staffsubset) {
			$td = new toolDialog($this->usertools);

			if ($td->ShowModal() == wxID_CANCEL)
				exit(NWC2RC_SUCCESS);

			list($groupname, $toolname) = $td->getUserTool();

			if ($this->executeall($staffsubset, $groupname, $toolname))
				$songchanged = true;
		}

		if ($songchanged)
			$this->SongData->OutputSongText(true);

		// can't get app to end by itself - due to never creating a main frame?!
		exit(NWC2RC_SUCCESS);
	}

	function getstaffsubset ($subsetname) {
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

	function verifyUserTool ($usertool) {
		// check first if $usertool is just a tool name (no group specified)
		foreach ($this->usertools as $groupname => $grouptools)
			if (isset($grouptools[$usertool]))
				return array($groupname, $usertool);

		// check second if $usertool is a fully specified group and tool name
		$groupname = strtok($usertool, ":");
		$toolname = strtok("");
		if (isset($this->usertools[$groupname][$toolname]))
			return array($groupname, $toolname);

		echo "RUT: Unrecognized user tool: $usertool".PHP_EOL;
		exit(NWC2RC_ERROR);
	}

	function getcommand ($groupname, $toolname) {
		$command = $this->usertools[$groupname][$toolname]["command"];

		if (preg_match('/<PROMPT:([^>]*)>/', $command)) {
			$pd = new parmDialog($groupname, $toolname, $command);

			if ($pd->ShowModal() == wxID_CANCEL)
				exit(NWC2RC_SUCCESS);

			$command = $pd->getFullCommand();
		}

		return $command;
	}

	function runcommand ($command, $stdin, &$stdout, &$stderr, $compress = false) {
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

	function executeone ($staffindex, $command, $compress = false) {
		$StaffData =& $this->SongData->StaffData[$staffindex];

		$stdin = $this->SongData->GetClipText($staffindex);

		$exitcode = $this->runcommand($command, $stdin, $stdout, $stderr, $compress);

		if (($exitcode == NWC2RC_SUCCESS) && !$stderr) {
			$clip = new NWC2Clip($stdout);

			// remove any fake items passed through by the user tool
			while ($clip->Items && ($o = new NWC2ClipItem($clip->Items[0])) && $o->IsContextInfo())
				array_shift($clip->Items);

			if ($StaffData->BodyItems === $clip->Items)
				return false;

			$StaffData->BodyItems = $clip->Items;
			return true;
		}

		if ($exitcode == NWC2RC_REPORT)
			$initialChoice = "STDOUT";
		else
			$initialChoice = "STDERR";

		$rd = new resultsDialog($StaffData->HeaderValues["AddStaff"]["Name"], $stdin, $stdout, $stderr, $initialChoice);

		if ($rd->ShowModal() == wxID_CANCEL)
			exit(NWC2RC_SUCCESS);

		return false;
	}

	function executeall ($staffsubset, $groupname, $toolname) {
		$command = $this->getcommand($groupname, $toolname);

		$compress = $this->usertools[$groupname][$toolname]["cmdflags"] & UTOOL_ACCEPTS_GZIP;

		$changed = false;

		foreach ($staffsubset as $staffindex)
			if ($this->executeone($staffindex, $command, $compress))
				$changed = true;

		return $changed;
	}
}

/*************************************************************************************************/

$ma = new mainApp();
wxApp::SetInstance($ma);
wxEntry();

?>
