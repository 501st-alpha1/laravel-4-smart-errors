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
	 * Construct the handler, injecting the Laravel application.
	 *
	 * @param Illuminate\Foundation\Application $app
	 */
	public function __construct($app)
	{
		$this->app = $app;

		$pkg = 'anlutro/l4-smart-errors::';

		$this->devEmail = $this->app['config']->get($pkg.'dev_email');

		// if configs are null, set some defaults
		$this->emailView = $this->app['config']->get($pkg.'email_view') ?: $pkg.'email';
		$this->alertEmailView = $this->app['config']->get($pkg.'alert_email_view') ?: $pkg.'alert_email';
		$this->exceptionView = $this->app['config']->get($pkg.'exception_view') ?: $pkg.'generic';
		$this->missingView = $this->app['config']->get($pkg.'missing_view') ?: $pkg.'missing';
		$this->dateFormat = $this->app['config']->get($pkg.'date_format') ?: 'Y-m-d H:i:s e';
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
		// log the exception
		if ($event) {
			$this->app['log']->error("Exception caught by event -- URL: $url -- Route: $route");
		} else {
			$this->app['log']->error("Uncaught Exception -- URL: $url -- Route: $route");
		}
		$this->app['log']->error($exception);

		// get any input and log it
		$input = $this->app['request']->all();
		if (!empty($input)) {
			$this->app['log']->error('Input: ' . json_encode($input));
		}

		// if debug is false and dev_email is set, send the mail
		if ($this->app['config']->get('app.debug') === false && $this->devEmail) {
			// I sometimes set pretend to true in staging, but would still like an email
			$this->app['config']->set('mail.pretend', false);

			$mailData = array(
				'exception' => $exception,
				'url'       => $this->app['request']->fullUrl(),
				'route'     => $this->findRoute(),
				'input'     => $input,
				'time'      => date($this->dateFormat),
			);

			$devEmail = $this->devEmail;
			$subject = $event ? 'Error report - event' : 'Error report - uncaught exception';
			$subject .= ' - '.$this->app['request']->root();

			$this->app['mailer']->send($this->emailView, $mailData, function($msg) use($devEmail, $subject) {
				$msg->to($devEmail)->subject($subject);
			});
		}

		// if debug is false, show the friendly error message
		if (!$event && $this->app['config']->get('app.debug') === false) {
			return $this->app['view']->make($this->exceptionView);
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
		$url = $this->app['request']->fullUrl();
		$referer = $this->app['request']->header('referer');

		$this->app['log']->warning("404 for URL $url -- Referer: $referer");

		$content = $this->app['view']->make($this->missingView);
		return new \Illuminate\Http\Response($content, 404);
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
		if ($this->app['config']->get('app.debug') !== false || empty($this->devEmail)) {
			return;
		}

		// I sometimes set pretend to true in staging, but would still like an email
		$this->app['config']->set('mail.pretend', false);

		$mailData = array(
			'message'   => $message,
			'context'   => $context,
			'url'       => $this->app['request']->fullUrl(),
			'route'     => $this->findRoute(),
			'time'      => date($this->dateFormat),
		);

		$devEmail = $this->devEmail;
		$subject = 'Alert logged';
		$subject .= ' - '.$this->app['request']->root();

		$this->app['mailer']->send($this->emailView, $mailData, function($msg) use($devEmail, $subject) {
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
		if ($this->app['router']->currentRouteAction()) {
			return $this->app['router']->currentRouteAction();
		} elseif ($this->app['router']->currentRouteName()) {
			return $this->app['router']->currentRouteName();
		} else {
			return 'NA (probably a closure)';
		}
	}
}
