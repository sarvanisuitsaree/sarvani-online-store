# Sarvani Suit & Saree – website

A static website (works on GitHub Pages) with a secure owner login. Products, photos and shop settings are stored in a free [Supabase](https://supabase.com) project. Follow the steps once; it takes about 15 minutes.

## 1. Create the backend (free)
1. Sign up at supabase.com → **New project** (any name, choose a region close to India, set a database password and keep it safe).
2. Open **SQL Editor → New query**, paste the whole content of `supabase-setup.sql`, click **Run**. It should say "Success".
3. Open **Authentication → Users → Add user → Create new user**. Enter the owner's **email** and a strong **password**, and tick **Auto Confirm User**.
4. Back in **SQL Editor**, run this (use the same email as step 3):
   ```sql
   insert into public.admins (user_id) select id from auth.users where email = 'owner@example.com';
   ```
5. Recommended: **Authentication → Sign In / Providers → Email** → turn off "Allow new users to sign up".
6. Open **Project Settings → API**. Copy the **Project URL** and the **anon public** key.

## 2. Connect the website
Open `config.js` and replace the two placeholder values with the URL and anon key from step 6. (The anon key is safe to publish; the rules in the SQL file stop anyone but the owner from changing data.)

## 3. Deploy on GitHub
1. Create a new repository on GitHub and upload **all files in this folder** (including `.nojekyll`, `vendor/` and `config.js`).
2. Go to **Settings → Pages**. Under *Build and deployment* choose **Deploy from a branch**, branch **main**, folder **/ (root)**, and Save.
3. After a minute your site is live at `https://<your-username>.github.io/<repository-name>/`.

## Using it
Click **Admin** on the site, sign in with the owner's email and password, then add products, photos and change shop details. The owner can change the password under the **Password** tab.

## Troubleshooting
- *"database is not connected yet"* → `config.js` still has the placeholder values, or the file was not uploaded.
- *"Incorrect email or password"* → check the user in Authentication → Users.
- *"This account is not allowed to manage the site"* → step 4 was skipped or used a different email.
- Photos fail to upload → make sure the whole `supabase-setup.sql` ran without errors.
