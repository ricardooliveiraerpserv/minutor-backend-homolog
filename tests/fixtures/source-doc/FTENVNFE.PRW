#INCLUDE "PROTHEUS.CH"
#INCLUDE "RWMAKE.CH"
#INCLUDE "TOPCONN.CH"
//      rotina para reenvio do arquivo xml da nota fiscal eletronica
//
User Function FTENVNFE()
LOCAL oDlg := NIL
LOCAL oDlgP := NIL
PRIVATE cTitulo := "Reenvio de arquivo XML da Nota Fiscal Eletronica"
Private cNumSerie := "001"
Private cNumNF := "000000"
Private cNumID := cNumSerie + cNumNF
Private cEmail := SPACE(250)

     DEFINE MSDIALOG oDlgP FROM 264,182 TO 441,613 TITLE cTitulo OF oDlgP PIXEL
     @ 08,010 Say OemToAnsi("Informe o numero de Serie e NF-e para reenvio:") Size 250,20 OF oDlgP PIXEL
     @ 32,030 Say "Serie"      Size 40,10 OF oDlgP PIXEL
     @ 30,70 GET cNumSerie      Size 40,10 OF oDlgP PIXEL
     @ 47,030 Say "No Nota Fiscal:"    Size 40,10 OF oDlgP PIXEL
     @ 45,70 GET cNumNF      Size 40,10 OF oDlgP PIXEL
     @ 62,030 Say "E-mail"      Size 40,10 OF oDlgP PIXEL
     @ 60,70 GET cEmail      Size 120,10 OF oDlgP PIXEL
     @ 28,167 BUTTON "OK"      SIZE 036,012 ACTION (lOpcao:=.t.,close(oDlgP))     OF oDlgP PIXEL
     @ 44,167 BUTTON "Cancelar" SIZE 036,012 ACTION (lOpcao:=.f.,close(oDlgP))      OF oDlgP PIXEL
     ACTIVATE MSDIALOG oDlgP CENTERED
     if lOpcao
          cNumID := cNumSerie + cNumNF
          U_FTENVNFU ( cNumID , cEmail )
          ALERT("O arquivo XML da NFe esta sendo reenviado em ate 5 minutos.")
     else
        // botao cancelar - teste gmud
     endif
Return .T.

// ROTINA QUE ALTERA O STATUS E O EMAIL DE UM REGISTRO DE NF-E PARA REENVIO
// ID = NUMERO DE SERIE + NUMERO NF
//
User Function FTENVNFU( cId , cEmail )
     Local _cQuery := ""
     _cQuery := " UPDATE SPED050"
     _cQuery += " SET "
     _cQuery += " EMAIL = '" + alltrim(cEmail) +"' , "
     _cQuery += " STATUSMAIL = '1' "
     _cQuery += " WHERE NFE_ID = '"+ cId +"' "
     //alert ( _cQuery )
     TCSQlExec(_cQuery)
Return
