<?php

// -------------
class NWC2Song {
// -------------

var $Comments=array();
var $Version="";
var $Items=array();

function NWC2Song($src='php://stdin')
{
	if (is_array($src)) $this->Items = $src;
	else if (!strstr($src,"\n")) $this->Items = gzfile($src);
	else if (($line = strtok($src,"\n")) !== false) {
		while ($line !== false) {
			$this->Items[] = $line."\n";
			$line = strtok("\n");
			}
		}

	if (!is_array($this->Items))
		trigger_error("Song text from NoteWorthy Composer 2 is required as input to the NWC2Song object",E_USER_ERROR);

	while ($this->Items && preg_match('/^#\/(.*)/', $this->Items[0], $m)) {
		$this->Comments[] = $m[1];
		array_shift($this->Items);
		}

	if (count($this->Items) < 2)
		trigger_error("Format error in the song text",E_USER_ERROR);

	$hdr = trim(array_shift($this->Items));

	if (preg_match('/^'.NWC2_STARTFILETXT.'\(([0-9]+)\.([0-9]+)/',$hdr,$m)) {
		$this->Version = "$m[1].$m[2]";
		}
	else trigger_error("Unrecognized notation song format",E_USER_ERROR);
	//
	$ftr = false;
	while (($ftr != NWC2_ENDFILETXT) && (count($this->Items) > 0)) $ftr = trim(array_pop($this->Items));
	//
	if ($ftr != NWC2_ENDFILETXT) trigger_error("Could not find song ending tag",E_USER_ERROR);
}

function GetSongHeader()
{ 
	return NWC2_STARTFILETXT."(".$this->Version.")";
}

function GetSongFooter()
{ 
	return NWC2_ENDFILETXT;
}
};

?>