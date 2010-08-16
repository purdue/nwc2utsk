<?php

/*
   Use this dialog to prompt the user for a subset of song staffs.

   Example usage:

	$SongData = new ParseSong();

	$sd = new staffDialog($SongData);

	if ($sd->ShowModal() == wxID_CANCEL)
		exit(NWC2RC_SUCCESS);

	$staffsubset = $sd->getStaffSubset();

   Copyright © 2010 by Randy Williams
   All Rights Reserved
*/

require_once("lib/nwc2gui.inc");
require_once("lib/nwc2parse.inc");

class staffDialog extends wxDialog
{
	private $SongData = null;

	private $grouplist = array();
	private $stafflist = array();

	private $groupobject = null;
	private $staffobject = null;

	private $groupselected = array();
	private $staffselected = array();

	function __construct ($SongData) {
		$this->SongData = $SongData;

		foreach ($this->SongData->StaffData as $StaffData) {
			if (!in_array($StaffData->HeaderValues["AddStaff"]["Group"], $this->grouplist)) {
				$this->grouplist[] = $StaffData->HeaderValues["AddStaff"]["Group"];
				$this->groupselected[] = false;
			}

			$this->stafflist[] = $StaffData->HeaderValues["AddStaff"]["Name"];
			$this->staffselected[] = false;
		}

		//--------------------------------------------------------------------------------------

		parent::__construct(null, -1, "RUT: Select staff subset");

		$dialogSizer = new wxBoxSizer(wxHORIZONTAL);
		$this->SetSizer($dialogSizer);

		$wxID = wxID_HIGHEST;

		//--------------------------------------------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer, 0, wxGROW);

		$statictext = new wxStaticText($this, ++$wxID, "Groups:");
		$colSizer->Add($statictext, 0, wxTOP|wxLEFT, 10);

		$listbox = new wxListBox($this, ++$wxID, wxDefaultPosition, wxDefaultSize,
					 nwc2gui_wxArray($this->grouplist), wxLB_MULTIPLE|wxLB_HSCROLL);
		$colSizer->Add($listbox, 0, wxGROW|wxALL, 10);

		$this->Connect($wxID, wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "doSelectGroup"));
		$this->groupobject = $listbox;

		//--------------------------------------------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer, 0, wxGROW);

		$statictext = new wxStaticText($this, ++$wxID, "Staffs:");
		$colSizer->Add($statictext, 0, wxTOP|wxLEFT, 10);

		$listbox = new wxListBox($this, ++$wxID, wxDefaultPosition, wxDefaultSize,
					 nwc2gui_wxArray($this->stafflist), wxLB_MULTIPLE|wxLB_HSCROLL);
		$colSizer->Add($listbox, 0, wxGROW|wxALL, 10);

		$this->Connect($wxID, wxEVT_COMMAND_LISTBOX_SELECTED, array($this, "doSelectStaff"));
		$this->staffobject = $listbox;

		//--------------------------------------------------------------------------------------

		$colSizer = new wxBoxSizer(wxVERTICAL);
		$dialogSizer->Add($colSizer, 0, wxGROW);

		$button = new wxButton($this, wxID_OK);
		$colSizer->Add($button, 0, wxALL, 10);

		$button = new wxButton($this, wxID_CANCEL);
		$colSizer->Add($button, 0, wxALL, 10);

		//--------------------------------------------------------------------------------------

		$dialogSizer->Fit($this);
	}

	function doSelectGroup ($event) {
		$groupindex = $event->GetSelection();
		$this->groupselected[$groupindex] = $this->groupobject->IsSelected($groupindex);

		$group = $this->grouplist[$groupindex];
		$selected = $this->groupselected[$groupindex];

		foreach ($this->SongData->StaffData as $index => $StaffData) {
			if ($StaffData->HeaderValues["AddStaff"]["Group"] == $group) {
				$this->staffselected[$index] = $selected;

				if ($selected)
					$this->staffobject->SetSelection($index);
				else
					$this->staffobject->Deselect($index);
			}
		}		
	}

	function doSelectStaff ($event) {
		$staffindex = $event->GetSelection();
		$this->staffselected[$staffindex] = $this->staffobject->IsSelected($staffindex);

		$group = $this->SongData->StaffData[$staffindex]->HeaderValues["AddStaff"]["Group"];
		$selected = true;

		foreach ($this->SongData->StaffData as $index => $StaffData)
			if ($StaffData->HeaderValues["AddStaff"]["Group"] == $group)
				$selected = $selected && $this->staffselected[$index];

		$groupindex = array_search($group, $this->grouplist);
		$this->groupselected[$groupindex] = $selected;

		if ($selected)
			$this->groupobject->SetSelection($groupindex);
		else
			$this->groupobject->Deselect($groupindex);
	}

	function getStaffSubset () {
		$staffsubset = array();

		foreach ($this->staffselected as $index => $value)
			if ($value)
				$staffsubset[] = $index;

		return $staffsubset;
	}
}

?>
