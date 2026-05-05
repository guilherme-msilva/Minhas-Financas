@echo off
@echo ###################################
@echo ######       Git Push       #######
@echo ###################################
set /p commit=Commit:

git add .
git commit -m "%commit%"
git push origin main
