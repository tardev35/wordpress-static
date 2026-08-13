@echo off
rem ไฟล์นี้ต้องเป็น UTF-8 และจบบรรทัดแบบ CRLF เท่านั้น
rem ถ้าถูกเซฟเป็น LF อย่างเดียว cmd.exe จะอ่านบรรทัดแรกไม่ออกแล้วพังทั้งไฟล์
rem (git เก็บเป็น LF โดยปริยาย ZIP ที่โหลดจาก GitLab จึงเคยพังมาแล้ว)
rem .gitattributes บังคับ eol=crlf ไว้ให้แล้ว อย่าเอาออก

chcp 65001 >nul
cd /d "%~dp0"
title WP Static Export

if not exist "%~dp0tools\uploader\server.mjs" (
  echo.
  echo   เปิดไม่ได้ เพราะยังไม่ได้แตกไฟล์ ZIP ออกมาก่อน
  echo.
  echo   ตอนนี้กำลังดับเบิลคลิกไฟล์ที่อยู่ข้างในไฟล์ ZIP
  echo   Windows แตกออกมาให้แค่ไฟล์เดียว ตัวโปรแกรมจริงยังอยู่ใน ZIP ทั้งก้อน
  echo.
  echo   วิธีแก้
  echo     1. ปิดหน้าต่างนี้
  echo     2. คลิกขวาที่ไฟล์ ZIP แล้วเลือก Extract All
  echo     3. เข้าไปในโฟลเดอร์ที่แตกออกมา
  echo     4. ดับเบิลคลิกไฟล์นี้อีกครั้ง
  echo.
  pause
  exit /b 1
)

where node >nul 2>nul
if errorlevel 1 (
  type "tools\uploader\no-node.txt"
  pause
  exit /b 1
)

node tools\uploader\server.mjs --open

echo.
pause
