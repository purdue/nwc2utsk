<?php

/*
   Copyright © 2010 by Randy Williams
   All Rights Reserved
*/

require_once("lib/nwc2clips.inc");
require_once("lib/nwc2gui.inc");

define("GC_PAGEWIDTH", 400);

$builtinsubsets = array(
	"all" => null,
	"visible" => array("Visible", "Y"),
	"hidden" => array("Visible", "N"),
	"audible" => array("Muted", "N"),
	"muted" => array("Muted", "Y"),
	"stdstyle" => array("Style", "Standard"),
	"orchestral" => array("Style", "Orchestral"),
	"oneline" => array("Lines", 1),
	"fivelines" => array("Lines", 5),
	"devicezero" => array("Device", 0),
	"deviceone" => array("Device", 1),
	"maxvolume" => array("Volume", 127),
	"channelten" => array("Channel", 10));

/*************************************************************************************************/

class _wxChoice extends wxChoice
{
	function __construct ($parent, $id) {
		parent::__construct($parent, $id);
	}

	function GetValue () {
		// GetStringSelection not available at this time
		$selection = $this->GetSelection();

		if ($selection >= 0)
			return $this->GetString($selection);
		else
			return "";
	}

	function SetValue ($value) {
		if ($value != "")
			$selection = $this->FindString($value, true);
		else
			$selection = -1;

		// SetStringSelection not available at this time
		$this->SetSelection($selection);
	}
}

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
	var $BodyObjects = array();

	private $DefaultsAdded = false;
	private $MappingsAdded = false;
	private $ImplodesAdded = false;

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

	function ConstructBodyObjects ($force = false) {
		if ($this->BodyObjects && !$force)
			return;

		$defaults = $this->DefaultsAdded;
		$mappings = $this->MappingsAdded;
		$implodes = $this->ImplodesAdded;

		$this->BodyObjects = array();

		$this->DefaultsAdded = false;
		$this->MappingsAdded = false;
		$this->ImplodesAdded = false;

		foreach ($this->BodyItems as $BodyItem)
			$this->BodyObjects[] = new NWC2ClipItem($BodyItem);

		$this->AddBodyObjectFixups($defaults, $mappings, $implodes);
	}

	private function BuildBodyObjectDefaults (&$defaults) {
		$defaults["Bar"]["Repeat"] = "N/A";
		$defaults["Bar"]["Style"] = "Single";
		$defaults["Bar"]["SysBreak"] = "N";
		$defaults["Bar"]["XBarCnt"] = "N";

		$defaults["Clef"]["OctaveShift"] = "None";

		$defaults["Dynamic"]["Opts"]["Velocity"] = "Default";
		$defaults["Dynamic"]["Opts"]["Volume"] = "Default";

		$defaults["Instrument"]["Bank"] = "N";
		$defaults["Instrument"]["Name"] = "N/A";
		$defaults["Instrument"]["Patch"] = "N";

		$defaults["Key"]["HideCancels"] = "N";

		$defaults["Rest"]["Opts"]["VertOffset"] = "0";

		$defaults["RestChord"]["Opts"]["HideRest"] = "N";
		$defaults["RestChord"]["Opts"]["VertOffset"] = "0";

		$defaults["SustainPedal"]["Status"] = "Down";

		$defaults["Tempo"]["Base"] = "Quarter";
		$defaults["Tempo"]["Text"] = "N/A";

		$defaults["TempoVariance"]["Pause"] = "N/A";

		for ($i=2; $i<=4; $i++)
			$defaults["MPC"]["Pt$i"] = "N/A";

		foreach (array("Note", "Chord", "RestChord") as $objtype) {
			$defaults[$objtype]["Opts"]["ArticulationsOnStem"] = "N";
			$defaults[$objtype]["Opts"]["Beam"] = "None";
			$defaults[$objtype]["Opts"]["Muted"] = "N";
			$defaults[$objtype]["Opts"]["NoLegerLines"] = "N";
			$defaults[$objtype]["Opts"]["StemLength"] = "Default";
			$defaults[$objtype]["Opts"]["Tie"] = "Default";
			$defaults[$objtype]["Opts"]["XAccSpace"] = "0";
			$defaults[$objtype]["Opts"]["XNoteSpace"] = "0";
		}

		foreach (array("Rest", "Note", "Chord", "RestChord") as $objtype) {
			$defaults[$objtype]["Opts"]["Crescendo"] = "N";
			$defaults[$objtype]["Opts"]["Diminuendo"] = "N";
			$defaults[$objtype]["Opts"]["Lyric"] = "Default";
			$defaults[$objtype]["Opts"]["Slur"] = "Default";
			$defaults[$objtype]["Opts"]["Stem"] = "Default";

			foreach (array("Dur", "Dur2") as $dur) {
				if (in_array($objtype, array("Rest", "Note")) && ($dur == "Dur2"))
					continue;

				$defaults[$objtype][$dur]["Accent"] = "N";
				$defaults[$objtype][$dur]["Dotted"] = "N";
				$defaults[$objtype][$dur]["DblDotted"] = "N";
				$defaults[$objtype][$dur]["Grace"] = "N";
				$defaults[$objtype][$dur]["Marcato"] = "N";
				$defaults[$objtype][$dur]["Slur"] = "N";
				$defaults[$objtype][$dur]["Staccato"] = "N";
				$defaults[$objtype][$dur]["Staccatissimo"] = "N";
				$defaults[$objtype][$dur]["Tenuto"] = "N";
				$defaults[$objtype][$dur]["Triplet"] = "None";
			}
		}

		foreach (array("Dynamic", "DynamicVariance", "Flow", "Instrument", "MPC", "PerformanceStyle", "SustainPedal", "Tempo", "TempoVariance", "Text") as $objtype) {
			$defaults[$objtype]["Justify"] = "Left";
			$defaults[$objtype]["Placement"] = "BestFit";
			$defaults[$objtype]["Wide"] = "N";

			$defaults[$objtype]["Color"] = "0";
			$defaults[$objtype]["Visibility"] = "Default";
		}

		foreach (array("Bar", "Clef", "Ending", "Key", "Spacer", "TimeSig", "Rest", "Note", "Chord", "RestChord") as $objtype) {
			$defaults[$objtype]["Color"] = "0";
			$defaults[$objtype]["Visibility"] = "Default";
		}
	}

	private function BodyObjectDefaults (&$o, $add) {
		static $defaults = array();

		if (!$defaults)
			$this->BuildBodyObjectDefaults($defaults);

		$objtype = $o->GetObjType();

		if (isset($defaults[$objtype])) {
			foreach ($defaults[$objtype] as $fld => $val) {
				if (is_array($val)) {
					foreach ($val as $fld2 => $val2) {
						if (isset($o->Opts[$fld][$fld2]) != $add) {
							if ($add)
								$o->Opts[$fld][$fld2] = $val2;
							else if ($o->Opts[$fld][$fld2] === $val2) {
								unset($o->Opts[$fld][$fld2]);

								if (empty($o->Opts[$fld]))
									unset($o->Opts[$fld]);
							}
						}
					}
				}
				else {
					if (isset($o->Opts[$fld]) != $add) {
						if ($add)
							$o->Opts[$fld] = $val;
						else if ($o->Opts[$fld] === $val)
							unset($o->Opts[$fld]);
					}
				}
			}
		}
	}

	private function BuildBodyObjectMappings (&$mappings) {
		$mappings["RestChord"]["Opts"]["HideRest"] = array("" => "Y");

		foreach (array("Note", "Chord", "RestChord") as $objtype) {
			$mappings[$objtype]["Opts"]["ArticulationsOnStem"] = array("" => "Y");
			$mappings[$objtype]["Opts"]["Beam"] = array("" => "Middle");
			$mappings[$objtype]["Opts"]["Muted"] = array("" => "Y");
			$mappings[$objtype]["Opts"]["NoLegerLines"] = array("" => "Y");
		}

		foreach (array("Rest", "Note", "Chord", "RestChord") as $objtype) {
			$mappings[$objtype]["Opts"]["Crescendo"] = array("" => "Y");
			$mappings[$objtype]["Opts"]["Diminuendo"] = array("" => "Y");

			foreach (array("Dur", "Dur2") as $dur) {
				if (in_array($objtype, array("Rest", "Note")) && ($dur == "Dur2"))
					continue;

				$mappings[$objtype][$dur]["Accent"] = array("" => "Y");
				$mappings[$objtype][$dur]["Dotted"] = array("" => "Y");
				$mappings[$objtype][$dur]["DblDotted"] = array("" => "Y");
				$mappings[$objtype][$dur]["Grace"] = array("" => "Y");
				$mappings[$objtype][$dur]["Marcato"] = array("" => "Y");
				$mappings[$objtype][$dur]["Slur"] = array("" => "Y");
				$mappings[$objtype][$dur]["Staccato"] = array("" => "Y");
				$mappings[$objtype][$dur]["Staccatissimo"] = array("" => "Y");
				$mappings[$objtype][$dur]["Tenuto"] = array("" => "Y");
				$mappings[$objtype][$dur]["Triplet"] = array("" => "Middle");
			}
		}
	}

	private function BodyObjectMappings (&$o, $add) {
		static $mappings = array();

		if (!$mappings)
			$this->BuildBodyObjectMappings($mappings);

		$objtype = $o->GetObjType();

		if (isset($mappings[$objtype])) {
			foreach ($mappings[$objtype] as $fld => $val) {
				// look ahead to see if we have array of arrays
				if (is_array(reset($val))) {
					foreach ($val as $fld2 => $val2) {
						if (!isset($o->Opts[$fld][$fld2]))
							continue;

						foreach ($val2 as $std => $new) {
							if ($add) {
								if ($o->Opts[$fld][$fld2] === $std)
									$o->Opts[$fld][$fld2] = $new;
							}
							else {
								if ($o->Opts[$fld][$fld2] === $new)
									$o->Opts[$fld][$fld2] = $std;
							}
						}
					}
				}
				else {
					foreach ($val as $std => $new) {
						if ($add) {
							if ($o->Opts[$fld] === $std)
								$o->Opts[$fld] = $new;
						}
						else {
							if ($o->Opts[$fld] === $new)
								$o->Opts[$fld] = $std;
						}
					}
				}
			}
		}
	}

	private function BodyObjectImplodes (&$o, $add) {
		$objtype = $o->GetObjType();

		// would like to loop over all fields in $o->Opts, call NWC2ClassifyOptTag for each field, and
		// implode the values iff NWC2OPT_LIST (or implode the keys iff NWC2OPT_ASSOCIATIVE), but this
		// is probably not worth the time hit, and exception fields (Pos/Pos2/Dur/Dur2/Opts) would need
		// to be hard-coded anyway, so may as well hard-code inclusion fields

		static $implodes = array("Instrument" => "DynVel", "Ending" => "Endings", "Key" => "Signature");

		if (isset($implodes[$objtype])) {
			$field = $implodes[$objtype];

			if (isset($o->Opts[$field])) {
				switch (NWC2ClassifyOptTag($objtype, $field)) {
					case NWC2OPT_LIST:
						if ($add)
							$o->Opts[$field] = implode(",", $o->Opts[$field]);
						else
							$o->Opts[$field] = explode(",", $o->Opts[$field]);
						break;

					case NWC2OPT_ASSOCIATIVE:
						if ($add)
							$o->Opts[$field] = implode(",", array_keys($o->Opts[$field]));
						else
							$o->Opts[$field] = array_fill_keys(explode(",", $o->Opts[$field]), "");
						break;
				}
			}
		}

		static $basedurs = array("Whole", "Half", "4th", "8th", "16th", "32nd", "64th");

		if (isset($o->Opts["Dur"])) {
			if ($add) {
				$base = current(array_intersect(array_keys($o->Opts["Dur"]), $basedurs));

				if ($base) {
					$o->Opts["Dur"]["Base"] = $base;
					unset($o->Opts["Dur"][$base]);
				}
			}
			else if (isset($o->Opts["Dur"]["Base"])) {
				$base = $o->Opts["Dur"]["Base"];

				if (in_array($base, $basedurs)) {
					// get base dur added at beginning of array, to preserve item text order
					$o->Opts["Dur"] = array_reverse($o->Opts["Dur"]);
					$o->Opts["Dur"][$base] = "";
					$o->Opts["Dur"] = array_reverse($o->Opts["Dur"]);

					unset($o->Opts["Dur"]["Base"]);
				}
			}
		}
	}

	function AddBodyObjectFixups ($defaults = true, $mappings = true, $implodes = true) {
		if (!$this->BodyObjects)
			return;

		if ($defaults && !$this->DefaultsAdded) {
			foreach ($this->BodyObjects as $o)
				$this->BodyObjectDefaults($o, true);

			$this->DefaultsAdded = true;
		}

		if ($mappings && !$this->MappingsAdded) {
			foreach ($this->BodyObjects as $o)
				$this->BodyObjectMappings($o, true);

			$this->MappingsAdded = true;
		}

		if ($implodes && !$this->ImplodesAdded) {
			foreach ($this->BodyObjects as $o)
				$this->BodyObjectImplodes($o, true);

			$this->ImplodesAdded = true;
		}
	}

	function SubBodyObjectFixups ($defaults = true, $mappings = true, $implodes = true) {
		if (!$this->BodyObjects)
			return;

		if ($implodes && $this->ImplodesAdded) {
			foreach ($this->BodyObjects as $o)
				$this->BodyObjectDefaults($o, false);

			$this->ImplodesAdded = false;
		}

		if ($mappings && $this->MappingsAdded) {
			foreach ($this->BodyObjects as $o)
				$this->BodyObjectMappings($o, false);

			$this->MappingsAdded = false;
		}

		if ($defaults && $this->DefaultsAdded) {
			foreach ($this->BodyObjects as $o)
				$this->BodyObjectImplodes($o, false);

			$this->DefaultsAdded = false;
		}
	}

	function ReconstructBodyItems () {
		if (!$this->BodyObjects)
			return;

		$this->BodyItems = array();

		if ($this->DefaultsAdded || $this->MappingsAdded || $this->ImplodesAdded) {
			print_r($this->BodyObjects);
			foreach ($this->BodyObjects as $o) {
				$o2 = clone $o;

				if ($this->ImplodesAdded)
					$this->BodyObjectImplodes($o2, false);

				if ($this->MappingsAdded)
					$this->BodyObjectMappings($o2, false);

				if ($this->DefaultsAdded)
					$this->BodyObjectDefaults($o2, false);

				$this->BodyItems[] = $o2->ReconstructClipText().PHP_EOL;
			}
			print_r($this->BodyObjects);
		}
		else {
			foreach ($this->BodyObjects as $o)
				$this->BodyItems[] = $o->ReconstructClipText().PHP_EOL;
		}
	}
}

/*************************************************************************************************/

class wizardPanel extends wxPanel
{
	private $nextButton = null;
	private $panelSizer = null;

	private $prompt = "";
	private $wxID = wxID_HIGHEST;

	function __construct ($parent, $nextButton, $prompt) {
		parent::__construct($parent);

		$this->nextButton = $nextButton;
		$this->updateNextButton();

		$this->panelSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($this->panelSizer);

		$this->prompt = $prompt;
		$this->Draw();
	}

	protected function Draw () {
		// create prompt field at maximum panel size, to force out other fields
		$statictext = new wxStaticText($this, $this->new_wxID(), $this->prompt,
				wxDefaultPosition, new wxSize(GC_PAGEWIDTH - 20, -1));
		$this->panelSizer->Add($statictext, 0, wxALL, 10);
	}

	protected function Redraw () {
		$this->panelSizer->Clear(true);
		$this->Draw();
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
		$this->panelSizer->Layout();
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
	const prompt = "Select all of the staff parts that you want globally changed:";

	private $SongData = null;

	private $groupStaffs = array();
	private $groupobject = null;

	private $group2Staffs = array();
	private $group2object = null;

	private $staffselected = array();
	private $staffobject = null;

	function __construct ($parent, $nextButton, $SongData, $staffsubset) {
		global $builtinsubsets;

		parent::__construct($parent, $nextButton, self::prompt);

		$this->SongData = $SongData;
		$virtGroupStaffs = array_fill_keys(array_keys($builtinsubsets), array());

		$stafflist = array();
		$grouplist = array();

		foreach ($this->SongData->StaffData as $staffindex => $StaffData) {
			$groupname = $StaffData->HeaderValues["AddStaff"]["Group"];
			$staffname = $StaffData->HeaderValues["AddStaff"]["Name"];

			if (!in_array($groupname, $grouplist)) {
				$grouplist[] = $groupname;
				$this->groupStaffs[] = array();
			}

			$stafflist[] = $staffname;
			$this->staffselected[] = false;

			$groupindex = array_search($groupname, $grouplist);
			$this->groupStaffs[$groupindex][] = $staffindex;

			foreach ($builtinsubsets as $subsetname => $subsetcond) {
				list($property, $value) = $subsetcond;

				if (!$property || ($StaffData->HeaderValues["StaffProperties"][$property] == $value))
					$virtGroupStaffs[$subsetname][] = $staffindex;
			}
		}

		if (count($grouplist) <= 1)
			unset($virtGroupStaffs["all"]);

		foreach (array_keys($virtGroupStaffs) as $virtGroup) {
			if ($virtGroup == "all")
				continue;

			if (count($virtGroupStaffs[$virtGroup]) == count($stafflist))
				unset($virtGroupStaffs[$virtGroup]);
			else if (count($virtGroupStaffs[$virtGroup]) == 0)
				unset($virtGroupStaffs[$virtGroup]);
		}

		$group2list = array_keys($virtGroupStaffs);
		$this->group2Staffs = array_values($virtGroupStaffs);

		//------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		//-------------------------------------------------
		// groups listboxes

		if ($group2list) {
			$groupheight = 190 * count($grouplist) / (count($grouplist) + count($group2list));
			$group2height = 190 - $groupheight;
			$staffheight = 210;
		}
		else {
			$groupheight = -1;
			$staffheight = -1;
		}

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 3);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Groups:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, new wxSize(-1, $groupheight),
					new wxphp_ArrayString($grouplist), wxLB_MULTIPLE);
		$colSizer->Add($listbox, 0, wxGROW|wxALIGN_LEFT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectGroup"));
		$this->groupobject = $listbox;

		if ($group2list) {
			$statictext = new wxStaticText($this, $this->new_wxID(), "Built-in Groups:");
			$colSizer->Add($statictext, 0, wxTOP, 8);

			$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, new wxSize(-1, $group2height),
						new wxphp_ArrayString($group2list), wxLB_MULTIPLE);
			$colSizer->Add($listbox, 0, wxGROW|wxALIGN_LEFT);

			$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectGroup2"));
			$this->group2object = $listbox;
		}

		//-------------------------------------------------
		// staffs listbox

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$rowSizer->Add($colSizer, 5, wxLEFT, 10);

		$statictext = new wxStaticText($this, $this->new_wxID(), "Staffs:");
		$colSizer->Add($statictext);

		$listbox = new wxListBox($this, $this->new_wxID(), wxDefaultPosition, new wxSize(-1, $staffheight),
					new wxphp_ArrayString($stafflist), wxLB_MULTIPLE);
		$colSizer->Add($listbox, 0, wxGROW|wxALIGN_RIGHT);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "handleSelectStaff"));
		$this->staffobject = $listbox;

		//------------------------------------------------------------------------

		$this->doFit();

		// reselect any staffs previously selected
		if ($staffsubset)
			$this->updateStaffs($staffsubset, true);
	}

	function updateSelection ($object, $index, $selected) {
		$method = ($selected ? "SetSelection" : "Deselect");
		$object->$method($index);
	}

	function isNextValid () {
		return in_array(true, $this->staffselected);
	}

	function updateAllGroups () {
		$selectedstaffs = array_keys($this->staffselected, true);

		foreach ($this->groupStaffs as $groupindex => $groupstaffs) {
			$selected = ($groupstaffs && !array_diff($groupstaffs, $selectedstaffs));
			$this->updateSelection($this->groupobject, $groupindex, $selected);
		}

		foreach ($this->group2Staffs as $groupindex => $groupstaffs) {
			$selected = ($groupstaffs && !array_diff($groupstaffs, $selectedstaffs));
			$this->updateSelection($this->group2object, $groupindex, $selected);
		}
	}

	function updateStaffs ($staffsubset, $selected) {
		foreach ($staffsubset as $staffindex) {
			$this->staffselected[$staffindex] = $selected;
			$this->updateSelection($this->staffobject, $staffindex, $selected);
		}

		$this->updateAllGroups();
		$this->updateNextButton();
	}

	function handleSelectGroup ($event) {
		$groupindex = $event->GetSelection();
		$selected = $this->groupobject->IsSelected($groupindex);

		$this->updateStaffs($this->groupStaffs[$groupindex], $selected);
	}

	function handleSelectGroup2 ($event) {
		$groupindex = $event->GetSelection();
		$selected = $this->group2object->IsSelected($groupindex);

		$this->updateStaffs($this->group2Staffs[$groupindex], $selected);
	}

	function handleSelectStaff ($event) {
		$staffindex = $event->GetSelection();
		$selected = $this->staffobject->IsSelected($staffindex);

		$this->updateStaffs(array($staffindex), $selected);
	}

	function getInputData () {
		return array_keys($this->staffselected, true);
	}
}

/*************************************************************************************************/

class searchPanel extends wizardPanel
{
	const prompt = "Select the set of objects that you wish to search for:";

	private $SongData = null;
	private $staffsubset = array();

	private $objmodel = array();
	private $objtypes = array();

	private $objtype = null; // needed?
	private $sizer = null; // needed?

	private $wxObjectCount = null;
	private $wxExpressionLogic = null; // needed?

	private $wxOperators = array(); // needed?
	private $wxTestValues = array(); // needed?

	private $operators = array("==", "!=", "<=", ">=", "<", ">");
	private $searchparms = array();

	private $currentcount = 0;
	private $addcondition = false;
	private $interestingfields = array();

	private static $selections = array();

	function __construct ($parent, $nextButton, $SongData, $staffsubset, $searchparms) {
		parent::__construct($parent, $nextButton, self::prompt);

		$this->SongData = $SongData;
		$this->staffsubset = $staffsubset;

		if ($searchparms)
			$this->searchparms = $searchparms;
		else
			$this->searchparms = array("Objtype" => "", "Logic" => "AND", "Tests" => array());

		//------------------------------------------------------------------------

		foreach ($staffsubset as $staffindex) {
			$StaffData =& $SongData->StaffData[$staffindex];

			foreach ($StaffData->BodyObjects as $o) {
				$objtype = $o->GetObjType();
				$hasnotes = in_array($objtype, array("Note", "Chord", "RestChord"));

				foreach ($o->Opts as $field => $value) {
					if ($hasnotes && in_array($field, array("Pos", "Pos2")))
						continue; // for now

					if (is_array($value)) {
						foreach ($value as $field2 => $value2)
							$this->objmodel[$objtype]["$field/$field2"][$value2] = "";
					}
					else
						$this->objmodel[$objtype][$field][$value] = "";
				}
			}
		}

		ksort($this->objmodel);

		foreach ($this->objmodel as &$fldmodel) {
			ksort($fldmodel);

			foreach ($fldmodel as &$valmodel)
				ksort($valmodel);
		}

		//------------------------------------------------------------------------

		$this->objtypes = array_keys($this->objmodel);

		if ($this->searchparms["Objtype"])
			foreach ($this->objmodel[$this->searchparms["Objtype"]] as $fldname => $valmodel)
				if (count($valmodel) > 1)
					$this->interestingfields[] = $fldname;

		$this->Redraw();
	}

	function Redraw () {
		parent::Redraw();

		$this->currentcount = $this->countSelected();

		//------------------------------------------------------------------------

		$rowSizer = $this->newRow();

		$statictext = new wxStaticText($this, $this->new_wxID(), "Object type:");
		$rowSizer->Add($statictext, 0, wxTOP|wxRIGHT, 3);

		$choicebox = new _wxChoice($this, $this->new_wxID());
		$choicebox->Append(new wxphp_ArrayString($this->objtypes));
		$choicebox->SetValue($this->searchparms["Objtype"]);
		$rowSizer->Add($choicebox);

		$this->Connect($this->cur_wxID(), wxEVT_COMMAND_CHOICE_SELECTED,
				array($this, "handleObjectType"));

		$rowSizer->AddStretchSpacer();

		$statictext = new wxStaticText($this, $this->new_wxID(), "Objects selected:");
		$rowSizer->Add($statictext, 0, wxTOP|wxRIGHT|wxALIGN_RIGHT, 3);

		$statictext = new wxStaticText($this, $this->new_wxID(), $this->currentcount);
		$rowSizer->Add($statictext, 0, wxTOP|wxALIGN_RIGHT, 3);

		//------------------------------------------------------------------------

		if ($this->searchparms["Objtype"]) {
			if ($this->searchparms["Tests"]) {
				$rowSizer = $this->newRow();

				$gridSizer = new wxFlexGridSizer(0, 4, 10, 10);
				$gridSizer->SetFlexibleDirection(wxHORIZONTAL);
				$gridSizer->AddGrowableCol(2, 1);
				$rowSizer->Add($gridSizer, 1, wxTOP|wxBOTTOM, 3);

				$this->wxIDbase = $this->cur_wxID() + 1;

				foreach ($this->searchparms["Tests"] as $test) {
					$statictext = new wxStaticText($this, $this->new_wxID(), $test["Field"]);
					$gridSizer->Add($statictext, 0, wxTOP, 3);

					$choicebox = new _wxChoice($this, $this->new_wxID());
					$choicebox->Append(new wxphp_ArrayString($this->operators));
					$choicebox->SetValue($test["Operator"]);
					$gridSizer->Add($choicebox);

					$this->Connect($this->cur_wxID(), wxEVT_COMMAND_CHOICE_SELECTED,
							array($this, "handleTestOperator"));

					$choicebox = new _wxChoice($this, $this->new_wxID());
					$choicebox->Append(new wxphp_ArrayString(array_keys($this->objmodel[$this->searchparms["Objtype"]][$test["Field"]])));
					$choicebox->SetValue($test["Value"]);
					$gridSizer->Add($choicebox, 0, wxGROW);

					$this->Connect($this->cur_wxID(), wxEVT_COMMAND_CHOICE_SELECTED,
							array($this, "handleTestValue"));

					$button = new wxButton($this, $this->new_wxID(), "Remove condition");
					$gridSizer->Add($button);

					$this->Connect($this->cur_wxID(), wxEVT_COMMAND_BUTTON_CLICKED,
							array($this, "handleRemoveCondition"));
				}
			}

			$rowSizer = $this->newRow();

			if ($this->addcondition) {
				$choicebox = new _wxChoice($this, $this->new_wxID());
				$choicebox->Append(new wxphp_ArrayString($this->interestingfields));
				$rowSizer->Add($choicebox);

				$this->Connect($this->cur_wxID(), wxEVT_COMMAND_CHOICE_SELECTED,
						array($this, "handleNewCondition"));
			}
			else if ($this->interestingfields && (count($this->searchparms["Tests"]) < 5)) {
				$button = new wxButton($this, $this->new_wxID(), "Add condition");
				$rowSizer->Add($button);

				$this->Connect($this->cur_wxID(), wxEVT_COMMAND_BUTTON_CLICKED,
						array($this, "handleAddCondition"));
			}

			if (count($this->searchparms["Tests"]) > 1) {
				$rowSizer->AddStretchSpacer();

				$statictext = new wxStaticText($this, $this->new_wxID(), "Expression logic:");
				$rowSizer->Add($statictext, 0, wxTOP|wxRIGHT|wxALIGN_RIGHT, 3);

				$choicebox = new _wxChoice($this, $this->new_wxID());
				$choicebox->Append(new wxphp_ArrayString(array("AND", "OR")));
				$choicebox->SetValue($this->searchparms["Logic"]);
				$rowSizer->Add($choicebox, 0, wxALIGN_RIGHT);

				$this->Connect($this->cur_wxID(), wxEVT_COMMAND_CHOICE_SELECTED,
						array($this, "handleExpressionLogic"));
			}
		}

		$this->doFit();

		$this->UpdateNextButton();
	}

	function handleObjectType ($event) {
		if (($objtype = $event->GetString()) !== "") {
			if ($this->searchparms["Objtype"])
				self::$selections[$this->searchparms["Objtype"]] = $this->searchparms;

			if (isset(self::$selections[$objtype]))
				$this->searchparms = self::$selections[$objtype];
			else
				$this->searchparms = array("Objtype" => $objtype, "Logic" => "AND", "Tests" => array());

			$this->interestingfields = array();
			foreach ($this->objmodel[$this->searchparms["Objtype"]] as $fldname => $valmodel)
				if (count($valmodel) > 1)
					$this->interestingfields[] = $fldname;

			$this->addcondition = false;
			$this->Redraw();
		}
	}

	function handleAddCondition () {
		$this->addcondition = true;
		$this->Redraw();
	}

	function handleNewCondition ($event) {
		if (($field = $event->GetString()) !== "") {
			$this->searchparms["Tests"][] = array("Field" => $field, "Operator" => "==", "Value" => "");
			$this->addcondition = false;
			$this->Redraw();
		}
	}

	function handleRemoveCondition ($event) {
		$index = intval(($event->GetId() - $this->wxIDbase) / 4);
		array_splice($this->searchparms["Tests"], $index, 1);
		$this->Redraw();
	}

	function handleTestOperator ($event) {
		$index = intval(($event->GetId() - $this->wxIDbase) / 4);
		$this->searchparms["Tests"][$index]["Operator"] = $event->GetString();
		$this->Redraw();
	}

	function handleTestValue ($event) {
		if (($value = $event->GetString()) !== "") {
			$index = intval(($event->GetId() - $this->wxIDbase) / 4);
			$this->searchparms["Tests"][$index]["Value"] = $value;
			$this->Redraw();
		}
	}

	function handleExpressionLogic ($event) {
		$this->searchparms["Logic"] = $event->GetString();
		$this->Redraw();
	}

	function countSelected () {
		// for speed
		$objtype = $this->searchparms["Objtype"];
		$logic = (($this->searchparms["Logic"] == "AND") ? "&&" : "||");
		$tests = $this->searchparms["Tests"];

		if (!$objtype) return 0;
		$count = 0;

		foreach ($tests as $index => &$test) {
			if ($test["Value"] === "") {
				unset($tests[$index]);
				continue;
			}

			if (strpos($test["Field"], "/")) {
				$test["Field"] = strtok($test["Field"], "/");
				$test["Field2"] = strtok("");
			}

			$test["Value"] = '"'.addcslashes($test["Value"], "\$\"\\").'"';
		}
		unset($test);

		foreach ($this->staffsubset as $staffindex) {
			$StaffData =& $this->SongData->StaffData[$staffindex];

			foreach ($StaffData->BodyObjects as $o) {
				if ($o->GetObjType() != $objtype)
					continue;

				if (!$tests) {
					$count++;
					continue;
				}

				$expressions = array();

				foreach ($tests as $test) {
					if (isset($test["Field2"]))
						$realvalue = $o->Opts[$test["Field"]][$test["Field2"]];
					else
						$realvalue = $o->Opts[$test["Field"]];

					$realvalue = '"'.addcslashes($realvalue, "\$\"\\").'"';
					$expressions[] = "($realvalue {$test['Operator']} {$test['Value']})";
				}

				if (eval("return ".implode(" $logic ", $expressions).";"))
					$count++;
			}
		}

		return $count;
	}

	function isNextValid () {
		return ($this->currentcount > 0);
	}

	function getInputData () {
		return $this->searchparms;
	}
}

/*************************************************************************************************/

class mainDialog extends wxDialog
{
	private $SongData = null;

	private $dialogSizer = null;
	private $currentPanel = null;
	private $pagePanel = null;
	private $currentPage = null;

	private $statusText = null;
	private $backButton = null;
	private $nextButton = null;
	private $cancelButton = null;

	private $staffsubset = array();
	private $searchparms = array();
	private $instruction = null;
	private $changeparms = array();

	private $showsummary = false;
	private $needsverify = false;
	private $songchanged = false;

	function __construct () {
		parent::__construct(null, -1, "Global Change");

		$bundle = new iconBundle();
		$this->SetIcons($bundle);

		$bitmap = new logoBitmap();
		$this->bitmapHeight = $bitmap->GetHeight();

		$wxID = wxID_HIGHEST;

		$this->dialogSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($this->dialogSizer);

		//------------------------------------------------------------------------
		// first row is GC logo and page panel

		$rowSizer = new wxBoxSizer(wxHORIZONTAL);
		$this->dialogSizer->Add($rowSizer, 0, wxGROW);

		$staticbitmap = new wxStaticBitmap($this, ++$wxID, $bitmap);
		$rowSizer->Add($staticbitmap);

		$this->pagePanel = new wxPanel($this, ++$wxID, wxDefaultPosition, new wxSize(GC_PAGEWIDTH, -1));
		$rowSizer->Add($this->pagePanel, 0, wxGROW);

		//------------------------------------------------------------------------
		// second row is status msg and button set

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

		//------------------------------------------------------------------------

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

			case "editsearchparms":
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new searchPanel($this->pagePanel, $this->nextButton, $this->SongData, $this->staffsubset, $this->searchparms);
				break;

			case "editinstruction":
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new instructPanel($this->pagePanel, $this->nextButton, $this->instruction);
				break;

			case "editchangeparms":
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new changePanel($this->pagePanel, $this->nextButton, $this->SongData, $this->changeparms);
				break;

			case "editverification":
				$this->setupCtrlPanel("active", "inactive", "active", "");
				$this->currentPanel = new verifyPanel($this->pagePanel, $this->nextButton, $this->SongData, $this->staffsubset, $this->showsummary);
				break;

			case "editresults":
				$this->setupCtrlPanel("active", "inactive", "active", null);
				$this->currentPanel = new resultsPanel($this->pagePanel, $this->nextButton, $this->instructresults, $this->bitmapHeight);
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

			case "editsearchparms":
				$this->searchparms = $this->currentPanel->getInputData();
				break;

			case "editinstruction":
				$this->instruction = $this->currentPanel->getInputData();
				break;

			case "editchangeparms":
				$this->changeparms = $this->currentPanel->getInputData();
				break;

			case "editverification":
				$this->showsummary = $this->currentPanel->getInputData();
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
			case "editsearchparms":
				$this->gotoState("editstaffsubset");
				break;

			case "editinstruction":
				$this->gotoState("editsearchparms");
				break;

			case "editchangeparms":
				$this->gotoState("editinstruction");
				break;

			case "editverification":
				if ($this->instruction == "delete")
					$this->gotoState("editinstruction");
				else
					$this->gotoState("editchangeparms");
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
				$this->gotoState("getsearchparms");
				break;

			case "editsearchparms":
				$this->gotoState("getinstruction");
				break;

			case "editinstruction":
				if ($this->instruction == "delete")
					$this->gotoState("getverification");
				else
					$this->gotoState("getchangeparms");
				break;

			case "editchangeparms":
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
			$this->showsummary = true;
			array_shift($argv);
		}

		$this->SongData = new ParseSong();

		// get staff subset from args if any
		if ($argv && $this->isStaffSubset(strtolower(reset($argv))))
			$this->staffsubset = $this->mapStaffSubset(strtolower(array_shift($argv)));

		$this->gotoState("getstaffsubset");
	}

	function gotoState ($nextstate) {
		global $argv;

		static $ArgProgress = 0;
		static $ExecuteIndex = 0;

		while (true) {
			switch ($nextstate) {
				case "getstaffsubset":
					$this->needsverify = false;

					// ask for a staff subset, unless we have one already
					if ($this->staffsubset)
						$nextstate = "getsearchparms";
					else
						$nextstate = "editstaffsubset";

					$ArgProgress = 0;
					break;

				case "editstaffsubset":
					$this->setupPage("editstaffsubset");
					return;

				case "getsearchparms":
					// construct data for selected staffs, if not done already
					$this->setupCtrlPanel("inactive", "inactive", "inactive", "Building object model...");

					//foreach ($this->SongData->StaffData as $StaffData) {
					foreach ($this->staffsubset as $staffindex) {
						$StaffData =& $this->SongData->StaffData[$staffindex];

						$StaffData->ConstructBodyObjects();
						$StaffData->AddBodyObjectFixups(true, true, true);
					}

					// get search parms from args if any, else get them from user
					if (($ArgProgress < 1) && $argv && $this->isSearchParms(strtolower(reset($argv)))) {
						$this->searchparms = $this->mapSearchParms(strtolower(array_shift($argv)));
						$nextstate = "getinstruction";
					}
					else
						$nextstate = "editsearchparms";

					$ArgProgress++;
					break;

				case "editsearchparms":
					$this->setupPage("editsearchparms");
					return;

				case "getinstruction":
					// get instruction from args if any, else get it from user
					if (($ArgProgress < 2) && $argv && $this->isInstruction(strtolower(reset($argv)))) {
						$this->instruction = strtolower(array_shift($argv));

						if ($this->actiontype == "delete")
							$nextstate = "getverification";
						else
							$nextstate = "getchangeparms";
					}
					else
						$nextstate = "editinstruction";

					$ArgProgress++;
					break;

				case "editinstruction":
					$this->setupPage("editinstruction");
					return;

				case "getchangeparms":
					// get change parms from args if any, else get them from user
					if (($ArgProgress < 3) && $argv && $this->isChangeParms(strtolower(reset($argv)))) {
						$this->changeparms = $this->mapChangeParms(strtolower(array_shift($argv)));
						$nextstate = "getverification";
					}
					else
						$nextstate = "editchangeparms";

					$ArgProgress++;
					break;

				case "editchangeparms":
					$this->setupPage("editchangeparms");
					return;

				case "getverification":
					// ask for verification, unless not needed (command line mode)
					if (!$this->needsverify)
						$nextstate = "getresultsbegin";
					else
						$nextstate = "editverification";

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
					$this->getResults($staffindex);

					// display results if applicable
					if ($this->showsummary)
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

						$nextstate = "getresults";
					}
					else
						$nextstate = "editverification";

					break;

				case "getresultsdone":
					// get staff subset from args if any
					$this->parseStaffSubset($this->staffsubset);

					// if any args left, do another global change, else done
					if ($argv)
						$nextstate = "getstaffsubset";
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

	function isStaffSubset ($arg) {
		global $builtinsubsets;

		return array_key_exists(strtolower($arg), $builtinsubsets);
	}

	function mapStaffSubset ($arg) {
		global $builtinsubsets;

		list($property, $value) = $builtinsubsets[strtolower($arg)];

		$staffsubset = array();

		foreach ($this->SongData->StaffData as $staffindex => $StaffData)
			if (!$property || ($StaffData->HeaderValues["StaffProperties"][$property] == $value))
				$staffsubset[] = $staffindex;

		return $staffsubset;
	}

	function isSearchParms ($arg) {
		return false; // for now
	}

	function isInstruction ($arg) {
		return false; // for now
	}

	function isChangeParms ($arg) {
		return false; // for now
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
		$wxStream = new wxphp_MemoryInputStream(LOGODATA, array("base64"));

		$wxImage = new wxImage($wxStream, wxBITMAP_TYPE_PNG);
		parent::__construct($wxImage);
	}
}

/*************************************************************************************************/

// Embedded file code created by nwswEncodeFile.php
define('MSTREAM_GCICOBYERIC_ICO',
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

class iconBundle extends wxphp_IconBundle
{
	function __construct () {
		$wxStream = new wxphp_MemoryInputStream(MSTREAM_GCICOBYERIC_ICO, array("base64", "gz"));

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
