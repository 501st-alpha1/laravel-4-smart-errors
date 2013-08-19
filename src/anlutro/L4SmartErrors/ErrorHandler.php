<?php
/**
 * Laravel 4 Smart Errors
 *
 * @author    Andreas Lutro <anlutro@gmail.com>
 * @license   http://opensource.org/licenses/MIT
 * @package   Laravel 4 Smart Errors
 */

namespace anlutro\L4SmartErrors;

/**
 * The class that handles the errors. Obviously
 */
class ErrorHandler
{
	/**
	 * The Laravel application.
	 *
	 * @var Illuminate\Foundation\Application
	 */
	protected $app;

	/**
	 * The email to send error reports to.
	 *
	 * @var string
	 */
	protected $devEmail;
	
	/**
	 * The view to use for email error reports.
	 *
	 * @var string
	 */
	protected $emailView;

	/**
	 * The view for generic error messages.
	 *
	 * @var string
	 */
	protected $exceptionView;
	
	/**
	 * The view for 404 error messages.
	 *
	 * @var string
	 */
	protected $missingView;

	/**
	 * The PHP date format that should be used.
	 *
	 * @var string
	 */
	protected $dateFormat;

	/**
	 * Whether or not to send an email even with mail.pretend = true
	 *
	 * @var boolean
	 */
	protected $forceEmail;

	/**
	 * The name of the laravel package.
	 *
	 * @var string
	 */
	protected $package;

	/**
	 * Construct the handler, injecting the Laravel application.
	 *
	 * @param Illuminate\Foundation\Application $app
	 */
	public function __construct($package)
	{
		$this->package = $package;
	}

	public function setApplication($app)
	{
		$this->app = $app;

		$this->setConfig($app['config']);
		$this->setMailer($app['mailer']);
		$this->setLogger($app['log']);
		$this->setRequest($app['request']);
		$this->setRouter($app['router']);
		$this->setView($app['view']);
	}

	public function setConfig($config)
	{
		$pkg = $this->package . '::';
		$this->config = $config;

		$this->devEmail = $this->config->get($pkg.'dev_email');
		$this->forceEmail = $this->config->get($pkg.'force_email');
		$this->emailView = $this->config->get($pkg.'email_view') ?: $pkg.'email';
		$this->alertEmailView = $this->config->get($pkg.'alert_email_view') ?: $pkg.'alert_email';
		$this->exceptionView = $this->config->get($pkg.'exception_view') ?: $pkg.'generic';
		$this->missingView = $this->config->get($pkg.'missing_view') ?: $pkg.'missing';
		$this->dateFormat = $this->config->get($pkg.'date_format') ?: 'Y-m-d H:i:s e';
	}

	public function setMailer($mailer)
	{
		$this->mailer = $mailer;
	}

	public function setLogger($logger)
	{
		$this->logger = $logger;
	}

	public function setRequest($request)
	{
		$this->request = $request;
	}

	public function setRouter($router)
	{
		$this->router = $router;
	}

	public function setView($view)
	{
		$this->view = $view;
	}

	/**
	 * Handle an uncaught exception. Returns a view if config.app.debug == false,
	 * otherwise returns void to let the default L4 error handler do its job.
	 *
	 * @param  Exception $exception
	 * @param  integer   $code
	 * @param  boolean   $event      Whether the exception is handled via an event
	 *
	 * @return View|void
	 */
	public function handleException($exception, $code = null, $event = false)
	{
		$route = $this->findRoute();
		$url = $this->request->fullUrl();

		// log the exception
		if ($event) {
			$logstr = 'Exception caught by event';
		} else {
			$logstr = 'Uncaught Exception';
		}

		$logstr .= " (handled by L4SmartErrors)\nURL: $url -- Route: $route\n";
		$logstr .= $exception;

		// get any input and log it
		$input = $this->request->all();
		if (!empty($input)) {
			$logstr .= 'Input: ' . json_encode($input);
		}

		$this->logger->error($logstr);

		// if debug is false and dev_email is set, send the mail
		if ($this->config->get('app.debug') === false && $this->devEmail) {
			if ($this->forceEmail) {
				$this->config->set('mail.pretend', false);
			}

			$mailData = array(
				'exception' => $exception,
				'url'       => $url,
				'route'     => $route,
				'input'     => $input,
				'time'      => date($this->dateFormat),
			);

			$devEmail = $this->devEmail;
			$subject = $event ? 'Error report - event' : 'Error report - uncaught exception';
			$subject .= ' - '.$this->request->root();

			$this->mailer->send($this->emailView, $mailData, function($msg) use($devEmail, $subject) {
				$msg->to($devEmail)->subject($subject);
			});
		}

		// if debug is false, show the friendly error message
		if (!$event && $this->config->get('app.debug') === false) {
			return $this->view->make($this->exceptionView);
		}

		// if debug is true, do nothing and the default exception whoops page is shown
	}

	/**
	 * Handle a 404 error.
	 *
	 * @param  Exception $exception
	 *
	 * @return Response
	 */
	public function handleMissing($exception)
	{
		$url = $this->request->fullUrl();
		$referer = $this->request->header('referer');

		$this->logger->warning("404 for URL $url -- Referer: $referer");

		return $this->view->make($this->missingView);
	}

	/**
	 * Handle an alert-level logging event.
	 *
	 * @param  string $message
	 * @param  array $context
	 *
	 * @return void
	 */
	public function handleAlert($message, $context)
	{
		if ($this->config->get('app.debug') !== false || empty($this->devEmail)) {
			return;
		}

		if ($this->forceEmail) {
			$this->config->set('mail.pretend', false);
		}

		$mailData = array(
			'logmsg'    => $message,
			'context'   => $context,
			'url'       => $this->request->fullUrl(),
			'route'     => $this->findRoute(),
			'time'      => date($this->dateFormat),
		);

		$devEmail = $this->devEmail;
		$subject = 'Alert logged';
		$subject .= ' - '.$this->request->root();

		$this->mailer->send($this->alertEmailView, $mailData, function($msg) use($devEmail, $subject) {
			$msg->to($devEmail)->subject($subject);
		});
	}

	/**
	 * Get the action or name of the current route.
	 *
	 * @return string
	 */
	protected function findRoute()
	{
		if ($this->router->currentRouteAction()) {
			return $this->router->currentRouteAction();
		} elseif ($this->router->currentRouteName()) {
			return $this->router->currentRouteName();
		} else {
			return 'NA (probably a closure)';
		}
	}
}
