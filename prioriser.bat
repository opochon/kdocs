@echo off
REM Poser la priorite d'une demande. C'est le SEUL levier d'ordre d'Olivier.
REM   prioriser.bat D-KT-16 1     traitee en premier
REM   prioriser.bat D-KT-16       retire la priorite
setlocal & cd /d "%~dp0"
node -e "const fs=require('fs');const f='recette/demandes.json';const j=JSON.parse(fs.readFileSync(f,'utf8'));const d=j.demandes.find(x=>x.id===process.argv[1]);if(!d){console.error('[X] '+process.argv[1]+' inconnue');process.exit(2)}d.priorite=process.argv[2]?Number(process.argv[2]):null;fs.writeFileSync(f,JSON.stringify(j,null,2)+'\n');console.log(d.id+' priorite='+(d.priorite??'(aucune)')+'  '+d.demande.slice(0,70))" %*

