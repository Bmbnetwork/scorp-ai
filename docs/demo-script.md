# SCORP AI — Demo rehearsal script (Phase 8)

A short, honest run-through for a live demo or review. Practise it once end-to-end before presenting. Every claim below is something the app actually does; nothing is faked.

## Prep (do this before the demo, not on stage)

1. Revoke any API key that was ever pasted into a chat/screenshot; create a fresh Gemini key. Put real keys only in `.env` (git-ignored).
2. Start MySQL in XAMPP. Confirm `.env` `DB_USER` is non-empty and matches phpMyAdmin (an empty `DB_USER` makes PHP connect as a blank user and saving fails).
3. Run the one-command check:

   ```powershell
   cd C:\xampp\htdocs\SCORP-AI
   & "C:\xampp\php\php.exe" bin\setup.php