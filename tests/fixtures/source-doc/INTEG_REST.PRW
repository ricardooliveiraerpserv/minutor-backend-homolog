#INCLUDE "PROTHEUS.CH"
#INCLUDE "FWMVCDEF.CH"
// Integra cliente com serviço externo REST e grava log no SA1
User Function ZINTEG()
    Local oRest := FWRest():New("https://api.parceiro.com")
    Local cRet  := ""
    DbSelectArea("SA1")
    SA1->(DbSetOrder(1))
    If SA1->(DbSeek(xFilial("SA1")+"000001"))
        cRet := U_ZPARSE(oRest:GetResult())
        RecLock("SA1", .F.)
        SA1->A1_XSTAT := "OK"
        SA1->(MsUnlock())
    EndIf
Return cRet

Static Function ZLOG(cMsg)
    ConOut(cMsg)
Return Nil
