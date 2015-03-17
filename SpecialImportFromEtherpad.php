<?php

class SpecialImportFromEtherpad extends SpecialPage {

	private $errors = array();
	private $formErrors = array();

	public function __construct() {
		global $wgImportFromEtherpadSettings;
		parent::__construct('ImportFromEtherpad', 'createpage');
		$this->pathToPandoc = $wgImportFromEtherpadSettings->pathToPandoc;
		$this->pandocCmd = $wgImportFromEtherpadSettings->pandocCmd;
	}

	function execute( $par ) {
		// @todo verify this is in the right place and used correctly
		$this->checkReadOnly();

		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();

		// allow only users with create and edit permissons to access
		$user = $this->getUser();
		if ( !$user->isAllowedAny( 'createpage', 'edit' ) ) {
			throw new PermissionsError( 'createpage' );
		}

		$out->addWikiMsg('importfrometherpad-intro');
		$request = $this->getRequest();
		// if formsubmitted, process the request
		if ($request->wasPosted() && $request->getVal('action') == 'submit') {
			$this->loadRequest();
		}
		else {
			$this->displayForm();
		}
		// either way display the form
		// if unprocessed, basic form will be shown
		// otherwise will display with errors and/or result of import
	}

	function getGroupName() {
		return 'pagetools';
	}

	private function displayForm( $errors = array() ) {
		$message = '';
		$action = $this->getPageTitle()->getLocalURL(array('action'=>'submit'));
		$out = $this->getOutput();
		$user = $this->getUser();

		$request = $this->getRequest();

		// get values from request object
		$this->etherpadLink= $request->getText('etherpadLink');
		$this->targetpageTitle = $request->getText('targetpageTitle');
		$this->targetpageNs = $request->getIntOrNull('targetpageNs');

		if ( count ( $errors ) == 1 && isset ( $errors[0][0] ) && $errors[0][0] == 'targetpage-exists') {
				// change submit button to be replace or append
				$appendOrReplaceRadio = "<tr><td colspan='2'><strong>".$this->msg('importfrometherpad-append-or-replace-label')."</strong></td></tr>";
				$appendOrReplaceRadio .= "<tr><td></td>";
				$appendOrReplaceRadio .= "<td class='mw-submit'>";
				$appendOrReplaceRadio .= Xml::radioLabel($this->msg('importfrometherpad-append-btn')->text(), 'pageAppendOrReplace', 'append', 'mw-append');
				$appendOrReplaceRadio .= Xml::radioLabel($this->msg('importfrometherpad-replace-btn')->text(), 'pageAppendOrReplace', 'replace', 'mw-replace');
				$appendOrReplaceRadio .= "</td></tr>";
				$errors = array();
		} else {
				$appendOrReplaceRadio = '';
		}

		// display error message if there is one
		if($message) {
			$out->addHTML('<div class="error">'.$message.'</div>\n');
		}
		if ( $user->isAllowed( 'createpage' ) ) {
			$out->addHTML(
				Xml::fieldset($this->msg('importfrometherpad-fieldset-legend')->text()) .
				Xml::openElement(
					'form', array(
						'method' => 'post',
						'action' => $action,
						'id' => 'importfrometherpad-form'
					)
				) .
				$this->msg('importfrometherpad-text')->parseAsBlock() .
				Html::hidden('action', 'submit') .
				Xml::openElement('table',array('id'=>'importfrometherpad-table')) .
				"<tr><td class='mw-label'>" .
				Xml::label($this->msg('importfrometherpad-label-eplink')->text(), 'mw-eplink') .
				"</td>" .
				"<td class='mw-input'>" .
				Xml::input('etherpadLink', 50, ($this->etherpadLink), array('id' => 'mw-eplink', 'type'=>'text')) .
				"</td></tr>" .
				"<tr><td class='mw-label'>" .
				Xml::label($this->msg('importfrometherpad-label-targetpage')->text(), 'mw-targetpage') .
				"</td>" .
				"<td class='mw-input'>" .
				Html::namespaceSelector(
					array(
						'selected' => ($this->targetpageNs ? $this->targetpageNs : NS_MAIN)
					),
					array('name' => 'targetpageNs', 'id' => 'mw-targetpage-ns')
				) .
				Xml::input('targetpageTitle', 50, $this->targetpageTitle, array('id' => 'mw-targetpage', 'type'=>'text')) .
				"</td></tr>" .
				$appendOrReplaceRadio .
				"<tr><td></td>" .
				"<td class='mw-submit'>" .
				Xml::submitButton($this->msg('importfrometherpad-submitbtn')->text(), array('id' => 'importfrometherpad-submit')) .
				"</td></tr>" .
				Xml::closeElement('table') . 
				Html::hidden( 'editToken', $user->getEditToken() ) .
				Xml::closeElement('form') . 
				Xml::closeElement('fieldset')
			);
		} else {
			$out->addWikiMsg('importfrometherpad-nopermission');
		}
	}

	protected function loadRequest() {
		$request = $this->getRequest();
		$user = $this->getUser();

		// get values from request object
		// @todo make this an array so we can iterate on it
		$this->etherpadLink= $request->getText('etherpadLink');
		$this->targetpageTitle = $request->getText('targetpageTitle');
		$this->targetpageNs = $request->getIntOrNull('targetpageNs');
		$this->pageAppendOrReplace = $request->getVal('pageAppendOrReplace');

		// grab output object
		// https://doc.wikimedia.org/mediawiki-core/REL1_23/php/html/classSpecialPage.html#a1dd08360c4383ac5aff17107da7b2cd5
		$output = $this->getOutput();

		// initiate status object
		// use built-in status tracking 
		// https://doc.wikimedia.org/mediawiki-core/master/php/html/classStatus.html
		$this->result = new Status;

		// check edit token
		$this->token = $user->getEditToken();
		if ( !$user->matchEditToken( $request->getVal( 'editToken' ) ) ) {
			$this->result->fatal('import-token-mismatch');
		} 
		
		//check permissions
		if ( !$user->isAllowed( 'createpage' ) ) {
			throw new PermissionsError( 'createpage' );
		}

		// initiate exception var
		$exception = false;

		// try the import and catch any exceptions
		try {
			$importResult = $this->importEtherpad();
		} catch ( MWException $e ) {
			$exception = $e;
		}

		// now format output, starting with exceptions
		if ( $exception ) {
			// @todo use our own messages for this?
			$output->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>",
				array( 'importfailed', $exception->getMessage() )
			);
		} elseif ( !$this->result->isGood() ) {
			//show any fatal errors that are not exceptions
			// @todo use our own messages for this?
			$output->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>",
				array( 'importfailed', $this->result->getWikiText() )
			);
			//$this->displayForm();
		} else if ( !$importResult) {
			//$this->displayForm($this->formErrors);
		} else {
			// show success!
			$output->addWikiMsg( 'importfrometherpad-importsuccess' );
			if (isset($this->resultMessage)) {
				$output->addWikiMsg( $this->resultMessage );
			}
			$newLink = Linker::linkKnown($this->newTitle);
			$output->addHTML( $this->msg( 'importfrometherpad-newlink' )->rawParams( $newLink )->parseAsBlock() );

			// now clear request vars so form is re-displayed without previous input
			$request->unsetVal('etherpadLink');
			$request->unsetVal('targetpageTitle');
			$request->unsetVal('targetpageNs');
			$request->unsetVal('pageAppendOrReplace');

			// reset form errors array
			$this->formErrors = array();
		}
		$output->addHTML( '<hr />' );

		// always re-display form after loading request
		// if there are errors or other messages, form will show them
		$this->displayForm($this->formErrors);
	}

	private function importEtherpad() {
		// check validity of ep url
		// right now this just checks to make sure it's a valid URI
		// @todo investigate if there is a way to check for valid ep instance
		if ( !Http::isValidURI($this->etherpadLink) ) {
			$this->result->fatal('importfrometherpad-invalidetherpad');
			return false;
		}

		// check validity of targettitle
		// @todo check permissions if attempting to use namespaces?
		$this->newTitle = Title::makeTitleSafe($this->targetpageNs, $this->targetpageTitle);
		if ( is_null($this->newTitle) ) {
			$this->result->fatal( 'importfrometherpad-invalidpagetitle' );
			return false;
		}

		// does the target page already exist?
		// and has the user not already indicated we should append/or replace?
		if ( $this->newTitle->exists() && !isset($this->pageAppendOrReplace) ) {
			$this->formErrors = array( array( 'targetpage-exists' ) );
			return false;
		}

		// convert content
		// all the work of Pandoc converting from html to wikimarkup is here
		if ( !$this->convertContent() ) {
			$this->result->fatal( 'importfrometherpad-fail' );
			return false;
		}

		// save article
		$apiResult = $this->saveArticle();

		// now check results of save and set result message and return value accordingly
		if ( isset( $apiResult['edit'] ) && $apiResult['edit']['result'] == 'Success' ){
			if ( isset( $apiResult['edit']['new'] ) ) {
				$this->resultMessage = 'importfrometherpad-sucessful-new';
			}
			else if ( isset( $apiResult['edit']['oldrevid'] ) && $apiResult['edit']['oldrevid'] == 0 ) {
				$this->resultMessage = 'importfrometherpad-sucessful-update';
			}
			else if ( isset( $apiResult['edit']['nochange'] ) ) {
				$this->resultMessage = 'importfrometherpad-sucessful-nochange';
			}
			$this->result->setResult(true);
			return true;
		} else {
			$this->result->fatal( 'importfrometherpad-savefail' );
			return false;
		}
	}

	private function saveArticle() {
		$textOrAppendText = ( isset( $this->pageAppendOrReplace ) && $this->pageAppendOrReplace == 'append') ? 'appendtext' : 'text';
		// action for both page edit and create is 'edit'
		// https://www.mediawiki.org/wiki/API:Edit
		$action = 'edit';
		// @todo localize comment text?
		$comment = 'Page generated from '. $this->etherpadLink;
        $api = new ApiMain(
                new DerivativeRequest(
                $this->getRequest(), // Fallback upon $wgRequest if you can't access context
                array(
            'action' => $action,
            'title' => $this->newTitle,
            $textOrAppendText => $this->content, // can only use one of 'text' or 'appendtext'
            'summary' => $comment,
            'notminor' => true,
            'token' => $this->token
                ), true // was posted?
                ), true // enable write?
        );
		$api->execute(); // actually save the article.
		$apiResult = $api->getResult()->getData();
		error_log(var_export($apiResult, true));
		// get and return apiResult object
		return $api->getResult()->getData();
	}

	private function convertContent()
	{
		// derive the export url from etherpad url
		$exportUrl = $this->getExportUrl();
		// @todo add check that pandoc exists
		$panDocCmd = $this->pathToPandoc . $this->pandocCmd . " -f html -t mediawiki $exportUrl";
		$this->content = wfShellExec($panDocCmd);
		// replace the funky br's the ep classic gens with newlines
		$this->content = preg_replace('/<br\s*\/>/m',"\n",$this->content);
		return true;
	}

	private function getExportUrl()
	{
		$parsedUrl = parse_url($this->etherpadLink);
		// is it etherpad lite?
		// from what I can tell, etherpad lites always have /p as first part of path
		if( preg_match('/^\/p/', $parsedUrl['path']) ) {
			$exportUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'] . '/export/html';
		}
		else {
			// This is valid for classic etherpad
			$exportUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/' . 'ep/pad/export' . $parsedUrl['path'] . '/latest?format=html';
		}
		// @todo add check for inaccssiable pads and/or pad exports
		return $exportUrl;
	}

}


/* vim:set ts=4 sw=4 sts=4 noexpandtab: */