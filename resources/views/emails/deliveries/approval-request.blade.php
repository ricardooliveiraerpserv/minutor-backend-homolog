<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.5;">
    <div style="max-width: 560px; margin: 0 auto; padding: 24px;">
        <h2 style="margin: 0 0 4px; font-size: 18px;">Sua aprovação foi solicitada</h2>
        <p style="margin: 0 0 16px; color: #6b7280; font-size: 13px;">
            Uma atividade está aguardando a sua validação.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <tr>
                <td style="padding: 6px 0; color: #6b7280; width: 120px;">Atividade</td>
                <td style="padding: 6px 0; font-weight: 600;">{{ $d->title }}</td>
            </tr>
            @if($projectName)
            <tr>
                <td style="padding: 6px 0; color: #6b7280;">Projeto</td>
                <td style="padding: 6px 0;">{{ $projectName }}</td>
            </tr>
            @endif
            @if($stageName)
            <tr>
                <td style="padding: 6px 0; color: #6b7280;">Etapa</td>
                <td style="padding: 6px 0;">{{ $stageName }}</td>
            </tr>
            @endif
            @if($d->due_date)
            <tr>
                <td style="padding: 6px 0; color: #6b7280;">Prazo</td>
                <td style="padding: 6px 0;">{{ \Carbon\Carbon::parse($d->due_date)->format('d/m/Y') }}</td>
            </tr>
            @endif
        </table>

        @if($d->description)
        <p style="margin: 16px 0 0; padding: 12px; background: #f9fafb; border-radius: 6px; font-size: 13px; color: #374151;">
            {{ $d->description }}
        </p>
        @endif

        <p style="margin: 24px 0 0; font-size: 13px; color: #6b7280;">
            Acesse o Portal do Cliente para aprovar ou solicitar ajustes nesta atividade.
        </p>
    </div>
</body>
</html>
