<p>
	{{ $info->renderHtml() }}
</p>

<p><b>Exception stack trace</b></p>
<p><pre style="white-space:pre-wrap;">{{ $exception->trace }}</pre></p>

@if ($exception->previous)
<p>
	<b>Previous exception:</b> {{ $exception->previous->info }}
</p>
@endif

@if ($input)
<hr>
<p>
	<b>Input</b><br>
	<p>{{ $input->renderHtml() }}</p>
</p>
@endif

@if ($queryLog)
<hr>
<p>
	<b>Query log</b><br>
	{{ $queryLog->renderHtml() }}
</p>
@endif