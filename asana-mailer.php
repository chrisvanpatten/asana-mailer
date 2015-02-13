<?php
/**
 * Require the Composer autoloader
 */
require_once( 'vendor/composer/autoload.php' );

use Symfony\Component\Yaml\Parser;

class AsanaMailer {
	public $data;
	public $asana;
	public $mandrill;
	public $workspaces;

	function __construct() {
		/**
		 * Setup the YAML parser, load some yaml files
		 */
		$yaml       = new Parser();
		$this->data = $yaml->parse( file_get_contents( 'asana.yml' ) );

		/**
		 * Setup Asana
		 */
		$this->asana = new Asana( array(
			'apiKey' => $this->data['asana']['apiKey'],
		) );

		/**
		 * Set up Mandrill
		 */
		$this->mandrill = new \Dustinmoorman\Mandrill\Mandrill(
			$this->data['mandrill']['fromName'],
			$this->data['mandrill']['fromEmail'],
			$this->data['mandrill']['replyToEmail'],
			$this->data['mandrill']['apiKey']
		);

		// Set the list of workspaces to grab from
		$this->workspaces = $this->data['asana']['workspaces'];
	}

	/**
	 * getTasks
	 *
	 * Retrieve the list of tasks (and associated metadata) for a given workspace ID
	 * @return array
	 */
	function getTasks( $workspace ) {
		$opt_fields = array(
			'name',
			'due_on',
			'assignee_status',
			'parent.name',
			'parent.projects.name',
			'parent.projects.team.name',
			'projects.name',
			'projects.team.name',
			'workspace',
		);

		$options = array(
			'completed_since' => 'now',
			'opt_fields'      => implode( $opt_fields, ',' ),
		);

		$tasks = json_decode( $this->asana->getWorkspaceTasks( $workspace, 'me', $options ) );
		return $tasks->data;
	}
	
	/**
	 * renderWorkspaceTasks
	 *
	 * Renders a list of tasks associated with a given workspace
	 */
	function renderWorkspaceTasks( $workspace ) {
		$content = '';
		$tasks   = $this->getTasks( $workspace );

		foreach( $tasks as $task ) {
			if ( $task->assignee_status == 'upcoming' || $task->assignee_status == 'inbox' ) {
				$content .= $this->renderTask( $task );
			}
		}

		return $content . '<br><br>';
	}

	/**
	 * renderEmailHtml
	 *
	 * Generates HTML for the email to be sent
	 * @return string
	 */
	function renderEmailHtml() {
		$content = '';

		$content .= 'The following tasks are in your Asana list today.<br><br>';

		foreach ( $this->workspaces as $workspace ) {
			$content .= $this->renderWorkspaceTasks( $workspace );
		}

		return $content;
	}

	/**
	 * renderProject
	 *
	 * Renders the project breadcrumb for a give task object
	 * @return string
	 */
	function renderProject( $task ) {
		$content = '';

		// If we have a parent, make it the main task
		if ( isset( $task->parent ) ) {
			$parent = $task->parent->name;
			$task   = $task->parent;
		}

		// Get the task's team
		if ( ! empty( $task->projects[0]->team ) )
			$content .= $task->projects[0]->team->name . ' &rsaquo; ';

		// Get the task's project
		if ( is_array( $task->projects ) && ! empty( $task->projects ) )
			$content .= $task->projects[0]->name;

		// Add the parent if it exists
		if ( isset( $parent ) )
			$content .= ' &rsaquo; ' . $parent;

		if ( empty( $content ) )
			$content = 'No Project Assigned';

		return '<small style="color: #999; text-transform: uppercase; letter-spacing: 1px;">' . $content . '</small><br>';
	}

	/**
	 * taskUrl
	 *
	 * Returns a URL for a task object
	 * @return string
	 */
	function taskUrl( $task ) {
		$taskId    = $task->id;
		$projectId = $task->workspace->id;

		if ( isset( $task->parent ) )
			$task = $task->parent;

		if ( is_array( $task->projects ) && ! empty( $task->projects ) )
			$projectId = $task->projects[0]->id;

		return 'https://app.asana.com/0/' . $projectId . '/' . $taskId;
	}

	/**
	 * renderDueDate
	 *
	 * Render a styled textual representation of the due date
	 * @return string
	 */
	function renderDueDate( $date_due ) {
		$date_due   = new DateTime( $date_due, new DateTimeZone('America/New_York') );

		$date_due   = $date_due->format( 'd M Y' );

		return '<small style="color: #999; font-style: italic;">' . $date_due . '</small>';
	}

	/**
	 * renderTask
	 *
	 * Renders a HTML/CSS representation of a task object
	 * @return string
	 */
	function renderTask( $task ) {
		$content = '';

		$content .= '<p>';

		$content .= $this->renderProject( $task );
		$content .= '<a href="' . $this->taskUrl( $task ) . '"><strong>' . $task->name . '</strong></a> ';

		if ( ! empty( $task->due_on ) )
			$content .= $this->renderDueDate( $task->due_on );

		$content .= '</p>';

		return $content;
	}

	public function sendEmail() {
		$date_today = new DateTime( 'now', new DateTimeZone('America/New_York') );

		$this->mandrill->setTitle( sprintf( $this->data['mandrill']['subject'], $date_today->format( 'd M Y' ) ) );
		$this->mandrill->setHTML( $this->renderEmailHtml() );

		$this->mandrill->addRecipient( $this->data['mandrill']['toEmail'], $this->data['mandrill']['toName'] );

		return $this->mandrill->send();
	}
}

$mailer = new AsanaMailer;

$mailing = $mailer->sendEmail();

if ( is_array( $mailing ) && isset( $mailing[0] ) && is_null( $mailing[0]->reject_reason ) )
	exit( 0 );

exit( 1 );
