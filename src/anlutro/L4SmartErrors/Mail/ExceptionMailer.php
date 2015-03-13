<?php
/**
 * Laravel 4 Smart Errors
 *
 * @author    Andreas Lutro <anlutro@gmail.com>
 * @license   http://opensource.org/licenses/MIT
 * @package   l4-smart-errors
 */

namespace anlutro\L4SmartErrors\Mail;

use anlutro\L4SmartErrors\AppInfoGenerator;
use anlutro\L4SmartErrors\Presenters\ExceptionPresenter;
use anlutro\L4SmartErrors\Presenters\InputPresenter;
use anlutro\L4SmartErrors\Presenters\QueryLogPresenter;
use anlutro\L4SmartErrors\Traits\ConfigCompatibilityTrait;
use Exception;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Message;

class ExceptionMailer
{
	use ConfigCompatibilityTrait;

	protected $app;
	protected $exception;
	protected $appInfo;
	protected $input;
	protected $queryLog;

	public function __construct(
		Application $app,
		ExceptionPresenter $exception,
		AppInfoGenerator $appInfo,
		InputPresenter $input = null,
		QueryLogPresenter $queryLog = null
	) {
		$this->app = $app;
		$this->exception = $exception;
		$this->appInfo = $appInfo;
		$this->input = $input;
		$this->queryLog = $queryLog;
	}

	public function send($email)
	{
		if ($this->getConfig('smarterror::force-email')) {
			$this->app['config']->set('mail.pretend', false);
		}

		if ($this->getConfig('smarterror::expand-stack-trace')) {
			$this->exception->setDescriptive(true);
		}

		$mailData = array(
			'info'      => $this->appInfo,
			'exception' => $this->exception,
			'input'     => $this->input,
			'queryLog'  => $this->queryLog,
		);

		$env = $this->app->environment();

		$exceptionName = $this->getExceptionBaseName($this->exception->getException());
		$subject = "[$env] $exceptionName - ";
		$subject .= $this->app['request']->root() ?: $this->getConfig('app.url');
		$htmlView = $this->getConfig('smarterror::error-email-view') ?: 'smarterror::error-email';
		$plainView = $this->getConfig('smarterror::error-email-view-plain') ?: 'smarterror::error-email-plain';

		$callback = function(Message $msg) use($email, $subject) {
			$msg->to($email)->subject($subject);
		};

		$this->app['mailer']->send(array($htmlView, $plainView), $mailData, $callback);
	}

	protected function getExceptionBaseName(Exception $exception)
	{
		$exceptionName = get_class($exception);

		if (($pos = strrpos($exceptionName, '\\')) !== false) {
			$exceptionName = substr($exceptionName, ($pos + 1));
		}

		return $exceptionName;
	}
}
