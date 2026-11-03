{{--
    Shared shell for ALL outgoing mail: warm paper background, 520px white
    card with a 4px accent top bar, uppercase app-name eyebrow, muted footer.

    Built for maximum client compatibility, including Outlook Classic (the
    Word rendering engine). Rules that keep it robust — keep them if you edit:
      • Layout with role="presentation" tables, never CSS block layout.
      • Borders / padding / background go on <td> (Outlook ignores them on
        <p>/<a> and unreliably on <table>); pair every background with a
        bgcolor="" attribute.
      • font-family is set on EVERY text element — Outlook does not inherit it
        and defaults to Times New Roman otherwise.
      • No empty height-only cells (Outlook collapses them): the accent bar
        carries a &nbsp; with line-height forcing its height.
    border-radius / overflow are decorative; Outlook drops them harmlessly.

    Expects: $appName (string), optional $accent (hex, defaults to sage).
    Children fill @section('content') and @section('footer') (the text after
    the "Sent by {app} ·" prefix).
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--[if mso]>
    <style>
        table, td { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        td { mso-line-height-rule: exactly; }
    </style>
    <noscript><xml>
        <o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings>
    </xml></noscript>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#FBFAF5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FBFAF5" style="background-color:#FBFAF5;border-collapse:collapse;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <!--[if mso]>
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td>
                <![endif]-->
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:520px;max-width:520px;background-color:#ffffff;border:1px solid #E2DFD4;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td height="4" bgcolor="{{ $accent ?? '#4B6840' }}" style="height:4px;line-height:4px;font-size:4px;background-color:{{ $accent ?? '#4B6840' }};">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#2C2C2C;">
                            <p style="margin:0 0 4px;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#8E938E;">{{ $appName }}</p>
                            @yield('content')
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->
                <p style="margin:16px 0 0;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:11px;color:#8E938E;">Sent by {{ $appName }} · @yield('footer')</p>
            </td>
        </tr>
    </table>
</body>
</html>
