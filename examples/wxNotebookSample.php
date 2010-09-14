<?php
require_once("lib/nwc2clips.inc");
require_once("lib/nwc2gui.inc");

class nwcut_NotebookPage extends wxPanel
{
	private $PanelNum = -1;
	private $Parent;

	function nwcut_NotebookPage($parent,$panelnum)
	{
		parent::__construct($parent);

		$this->Parent = $parent;
		$this->PanelNum = $panelnum;

		$panelSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($panelSizer);
		$panelSizer->SetMinSize(300,200);
		$wxID = wxID_HIGHEST;

		$newrow = new wxBoxSizer(wxHORIZONTAL);
		$panelSizer->Add($newrow,0,wxGROW|wxALL,10);

		$statictext = new wxStaticText($this, ++$wxID, "This is panel $panelnum");
		$newrow->Add($statictext,0,wxGROW);
		//
		$newrow->AddSpacer(15);
		//
		$btn = new wxButton($this,++$wxID,"About Panel $panelnum");
		$newrow->Add($btn,0,wxGROW);
		$this->Connect($wxID,wxEVT_COMMAND_BUTTON_CLICKED,array($this,"onAbout"));

		$newrow = new wxStaticBoxSizer(wxVERTICAL,$this);
		$panelSizer->Add($newrow,0,wxGROW|wxALL,10);
		//
		for($i=0;$i<$panelnum;$i++) {
			$wxID++;

			if (($i%6) == 0) {
				if ($i) $newrow->AddSpacer(10);
				$newbox = new wxBoxSizer(wxHORIZONTAL);
				$newrow->Add($newbox,0,wxGROW);
				}
			else {
				$newbox->AddSpacer(10);
				}

			$btn = new wxButton($this,$wxID,"Test $wxID",wxDefaultPosition, wxDefaultSize);
			$newbox->Add($btn,0,wxGROW);
			$this->Connect($wxID,wxEVT_COMMAND_BUTTON_CLICKED,array($this,"doTestBtn"));
			}

		$panelSizer->Fit($this);
	}

	function doTestBtn($event)
	{
		$dlg = new wxMessageDialog($this->Parent,"Test button id ".$event->GetId()." pressed","Test",wxICON_INFORMATION);
		$dlg->ShowModal();
	}

	function onAbout()
	{
		$dlg = new wxMessageDialog($this->Parent,"Panel {$this->PanelNum} About Box","About box...",wxICON_INFORMATION);
		$dlg->ShowModal();
	}
}

class nwcut_NotebookFrame extends wxDialog
{
	private $NotebookPanel = false;
	private $NotebookSizer = false;
	private $CurrentPanel = false;
	private $PanelNum = 0;

	function nwcut_NotebookFrame()
	{
		parent::__construct(null,-1,"Sample Notebook Interface",wxDefaultPosition,new wxSize(690,400));
	
		$this->SetIcons(new nwc2gui_IconBundle);

		$wxID = wxID_HIGHEST;

		$this->NotebookSizer = new wxBoxSizer(wxVERTICAL);
		$this->SetSizer($this->NotebookSizer);

		$newrow = new wxBoxSizer(wxHORIZONTAL);
		$this->NotebookSizer->Add($newrow,0,wxGROW);
		//
		// Embedded file code created by nwswEncodeFile.php
		define('MSTREAM_SAMPLE_BMP',
			'eJztl8Fu1DAQhsf4kD1U9ZXDinDkEThULOLOHXFAeQSOvaWPFsSLGPECOaZSlGHs2OO/7QalUFQJ+a8cfbs7sX/bM0764eOXI0W9k/ZG2mdpn14QGfkLei2/'.
			'X7m1ydexcWjMVFVVVVVVVfXsajw17Fc+zWQz9/Kk7oeIhvmG2pUt80BOWW5euWEeya7svvNEJjEJ09rnK+pn4CXzkU7ArQzcAX9N7MRE5kZMII+Jg3uvPOpY'.
			'hkfjkx8xZAsvyb+7kRlz4oFOyqOYkJGD2llMpBeYdpGBF2XLs66oCTPO6j09kboNhgEMsAVugFtgMKf7LnLA/U9fQspYbiosvXQa4ul9DrmFXn5AL8Wlmwr3'.
			'XlkGUpaBlGWdC/MAPAEvwPwN+BZYfdrIyT+HJO40aMlzDEFDvpvjHdhTCdLZILfAFpiQW2DcC0LGvWuAca8xBzA3NvMHeY/29PPQzyFcLLADTnO8oHDsKOu6'.
			'XYayS+zkoNH6ZdbzxMVizFz8uBkY/vlwd/J9AmbkAXgCXjLH1MgfYv4ktRAkntMRci/oxOXcuB/kIchDkMaYMoAEjYV7NUQWQtx0NsTw+ZCTPxvSzGdDqPhK'.
			'Id3a4wIcJ+sTs1Z22N2x7GnZ66ficLSXvEI2mUMeWuCcn1G76gu52+D9ih7SGXWAfi4e8DruJXhz4fIv13MHi5+31Ci/zN4iF8/Hu9xtMGW+yuzu8rXyQKVO'.
			'vS08t1i/yFo5zQbjoW2h3A0wQWHGd6esE9SLgyLdocMD7uL1tzmQ6yuaTfxMOXAvH9QP5urFBh9gLvQnbB7JW+9LeA5svDvhM9pCGjxG/0G9X+e1irVvgc0O'.
			'pifha+gTeS5rZRZdqxvHQ5N4EE7+41Mw7XV8f4C9NrDXBp6WVVVVVVVVVX+jX2I+lOo=');
		$my_bitmap = new wxBitmap(new wxImage(new nwc2gui_MemoryInStream(MSTREAM_SAMPLE_BMP,array('gz','base64')),wxBITMAP_TYPE_BMP));
		//
		$imgControl = new wxStaticBitmap($this,++$wxID,$my_bitmap);
		$newrow->Add($imgControl, 0, wxGROW);

		$this->NotebookPanel = new wxNotebook($this,++$wxID,new wxPoint(0,0),wxDefaultSize,wxNB_TOP);
		$this->AddPanel(1);
		$this->AddPanel(9);
		$this->AddPanel(22);
		$this->AddPanel(32);
		$this->AddPanel(64);
		$newrow->Add($this->NotebookPanel,0,wxGROW);

		$ButtonPanel = new wxStaticBoxSizer(wxVERTICAL, $this);
		$this->NotebookSizer->Add($ButtonPanel,0,wxGROW|wxALL,0);
		
		$box = new wxBoxSizer(wxHORIZONTAL);
		$ButtonPanel->Add($box,0,wxALIGN_RIGHT|wxALL,5);

		$btn_cancel = new wxButton($this, wxID_CANCEL);
		$box->Add($btn_cancel,0,wxGROW|wxRIGHT,40);
		$this->Connect(wxID_CANCEL,wxEVT_COMMAND_BUTTON_CLICKED,array($this,"onQuit"));

		$btn_back = new wxButton($this, ++$wxID, "<- Back");
		$box->Add($btn_back,0,wxGROW|wxRIGHT,6);
		$this->Connect($wxID,wxEVT_COMMAND_BUTTON_CLICKED,array($this,"onPriorPanel"));

		$btn_next = new wxButton($this, ++$wxID, "Next ->");
		$box->Add($btn_next,0,wxGROW);
		$this->Connect($wxID,wxEVT_COMMAND_BUTTON_CLICKED,array($this,"onNextPanel"));

		$this->NotebookSizer->Fit($this);
	}

	function AddPanel($i)
	{
		$NewPage = new nwcut_NotebookPage($this->NotebookPanel,$i);
		$this->NotebookPanel->AddPage($NewPage,"$i Test Control".(($i == 1) ? '' : "s"));
	}

	function onPriorPanel()
	{
		$n = $this->NotebookPanel->GetSelection();
		$lastPage = $this->NotebookPanel->GetPageCount() - 1;

		$n--;
		if ($n < 0) $n = $lastPage;

		$this->NotebookPanel->SetSelection($n);
	}

	function onNextPanel()
	{
		$n = $this->NotebookPanel->GetSelection();
		$lastPage = $this->NotebookPanel->GetPageCount() - 1;

		$n++;
		if ($n > $lastPage) $n = 0;

		$this->NotebookPanel->SetSelection($n);
	}

	function onQuit()
	{
		$this->Destroy();
	}
}

class nwcut_MainApp extends wxApp 
{
	public $AppFrame = false;

	function OnInit()
	{
		$this->AppFrame = new nwcut_NotebookFrame();
		$this->AppFrame->Show();

		return 0;
	}
	
	function OnExit()
	{
		return 0;
	}
}

function nwcut_InitWX()
{
	// This init function is designed to protect the $App variable from the global scope, which prevents problems during Destroy
	$App = new nwcut_MainApp();
	wxApp::SetInstance($App);
	wxEntry();
}

nwcut_InitWX();

exit(NWC2RC_SUCCESS);
?>
