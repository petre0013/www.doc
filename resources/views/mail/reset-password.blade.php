@extends('mail.layout')

@section('content')
    <h1 style="margin:0 0 12px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:18px;color:#2C2C2C;">Reset your password</h1>
    <p style="margin:0 0 20px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#5A5A5A;">
        A password reset was requested for the account using this address.
        Click the button below to choose a new password.
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr>
            <td align="center" bgcolor="#4B6840" style="background-color:#4B6840;border-radius:6px;padding:12px 24px;mso-padding-alt:12px 24px;">
                <a href="{{ $url }}" style="display:inline-block;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;">
                    Reset password
                </a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 16px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#5A5A5A;">
        This link expires in {{ $expires }} minutes. If you didn't request a
        reset, no action is needed — your password stays as it is.
    </p>
    <p style="margin:0;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#8E938E;">
        If the button doesn't work, copy this address into your browser:<br>
        <a href="{{ $url }}" style="color:#4B6840;word-break:break-all;">{{ $url }}</a>
    </p>
@endsection

@section('footer', 'password reset requested for this address')
