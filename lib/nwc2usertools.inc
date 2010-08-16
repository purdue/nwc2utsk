<?php

/* Usage notes:

	$ut = new NWC2UserTools();

	$alltoolinfo = array_merge($ut->builtin, $ut->userdefined);

   Copyright © 2010 by Randy Williams
   All Rights Reserved
*/

require_once("lib/nwc2config.inc");

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

?>
