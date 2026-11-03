@extends('mail.layout')

@section('content')
    <h1 style="margin:0 0 12px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:18px;color:#2C2C2C;">Email settings test</h1>
    <p style="margin:0;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#5A5A5A;">
        This is a test message confirming your email (SMTP) settings are working.
        Password resets and notifications will be delivered to your users from now on.
    </p>
@endsection

@section('footer', 'automated email settings test')
