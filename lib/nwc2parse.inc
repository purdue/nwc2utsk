<?php

/* Usage notes:

   When expecting a clip (a single staff, or part thereof), this is done:
	$Clip = new NWC2Clip();

   When expecting an entire song, this can be done:
	$Song = new NWC2Song();

   However, to parse out the song header (by item and by value), and to
   parse out all the staffs (each header by item and by value, and each
   body by item), one can do this instead:
	$SongData = new ParseSong();

   Copyright © 2010 by Randy Williams
   All Rights Reserved

*/

require_once("lib/nwc2clips.inc");

// parse header items and staff data from a song's items
class ParseSong {
	var $SongHeader = null;
	var $SongFooter = null;

	var $Comments = array();
	var $Version = "";

	var $HeaderItems = array();
	var $HeaderValues = array();

	var $StaffData = array();

	function ParseSong ($src = 'php://stdin') {
		$Song = new NWC2Song($src);

		$this->SongHeader = $Song->GetSongHeader();
		$this->SongFooter = $Song->GetSongFooter();

		$this->Comments = $Song->Comments;
		$this->Version = $Song->Version;

		$index = 0;

		while (($index < count($Song->Items)) && $this->isHeaderItem($Song->Items[$index], true))
			$this->HeaderItems[] = $Song->Items[$index++];

		while (($index < count($Song->Items)) && !$this->isHeaderItem($Song->Items[$index], false))
			$this->StaffData[] = new ParseStaff($Song->Items, $index);
	}

	private function isHeaderItem ($item, $capture) {
		$ObjType = NWC2QuickGetObjectType($item);

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
	var $BodyObjects = array(); // optional

	private $DefaultsAdded = false;
	private $MappingsAdded = false;
	private $ImplodesAdded = false;

	function ParseStaff ($items, &$index) {
		while (($index < count($items)) && $this->isHeaderItem($items[$index], true))
			$this->HeaderItems[] = $items[$index++];

		while (($index < count($items)) && !$this->isHeaderItem($items[$index], false))
			$this->BodyItems[] = $items[$index++];
	}

	private function isHeaderItem ($item, $capture) {
		$ObjType = NWC2QuickGetObjectType($item);

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

	function ConstructBodyObjects () {
		$this->BodyObjects = array();

		foreach ($this->BodyItems as $BodyItem)
			$this->BodyObjects[] = new NWC2ClipItem($BodyItem);
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
		// implode the values iff NWC2OPT_LIST or implode the keys iff NWC2OPT_ASSOCIATIVE, but this is
		// probably not worth the time hit, and exception fields (Pos/Pos2/Dur/Dur2/Opts) would need to
		// be hard-coded anyway, so may as well hard-code inclusion fields

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
		if ($defaults && !$this->DefaultsAdded) {
			foreach ($this->BodyObjects as &$o)
				$this->BodyObjectDefaults($o, true);

			$this->DefaultsAdded = true;
		}

		if ($mappings && !$this->MappingsAdded) {
			foreach ($this->BodyObjects as &$o)
				$this->BodyObjectMappings($o, true);

			$this->MappingsAdded = true;
		}

		if ($implodes && !$this->ImplodesAdded) {
			foreach ($this->BodyObjects as &$o)
				$this->BodyObjectImplodes($o, true);

			$this->ImplodesAdded = true;
		}
	}

	function SubBodyObjectFixups ($defaults = true, $mappings = true, $implodes = true) {
		if ($implodes && $this->ImplodesAdded) {
			foreach ($this->BodyObjects as &$o)
				$this->BodyObjectDefaults($o, false);

			$this->ImplodesAdded = false;
		}

		if ($mappings && $this->MappingsAdded) {
			foreach ($this->BodyObjects as &$o)
				$this->BodyObjectMappings($o, false);

			$this->MappingsAdded = false;
		}

		if ($defaults && $this->DefaultsAdded) {
			foreach ($this->BodyObjects as &$o)
				$this->BodyObjectImplodes($o, false);

			$this->DefaultsAdded = false;
		}
	}

	function ReconstructBodyItems () {
		$this->BodyItems = array();

		if ($this->DefaultsAdded || $this->MappingsAdded || $this->ImplodesAdded) {
			foreach ($this->BodyObjects as &$o) {
				$o2 = clone $o;

				if ($this->ImplodesAdded)
					$this->BodyObjectImplodes($o2, false);

				if ($this->MappingsAdded)
					$this->BodyObjectMappings($o2, false);

				if ($this->DefaultsAdded)
					$this->BodyObjectDefaults($o2, false);

				$this->BodyItems[] = $o2->ReconstructClipText().PHP_EOL;
			}
		}
		else {
			foreach ($this->BodyObjects as &$o)
				$this->BodyItems[] = $o->ReconstructClipText().PHP_EOL;
		}
	}
}

?>
