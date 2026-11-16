# Enable SMS on the online backend

The React frontend never needs the Semaphore API key. Configure it on the server running Laravel.

## This project's Render deployment

The deployed frontend at `https://botika-frontend.onrender.com` connects to `https://botika-backend-x44e.onrender.com/api`.
In the Render dashboard, open the **backend web service** matching that URL, then open **Environment** and add:

```dotenv
SEMAPHORE_API_KEY=your-private-api-key
SEMAPHORE_SENDER_NAME=your-exact-active-sender-name
```

Save the environment changes and redeploy the backend. This repository's `docker/start.sh` runs `php artisan config:cache` at startup, so the new variables are picked up automatically. Do not add these values to the frontend service.

After deploying, run `php artisan botika:sms-check` in the backend Shell if your Render plan provides one. If no Shell is available, use a single test account you control to verify delivery through the app after checking credits and sender activation in Semaphore.

## Provider setup and verification

1. In the Semaphore dashboard, check that the account is active, credits are available and a sender name is active.
2. In the backend hosting service's environment settings, set `SEMAPHORE_API_KEY` to your key and `SEMAPHORE_SENDER_NAME` to the exact active sender name. Keep keys out of Git, screenshots and frontend `VITE_` variables.
3. Redeploy or restart the backend. If configuration is cached, run `php artisan config:cache` after setting the variables.
4. In the backend shell, run `php artisan botika:sms-check`. This checks credentials, credits and sender names without sending messages or using SMS credits. Semaphore rate-limits account checks; avoid repeatedly running it.
5. With a resident test account using your own mobile number, submit a request and approve it once as pharmacy staff. Check both the app's SMS log and the recipient's phone. This sends a real message and uses credits. Avoid broadcasting announcements as a connectivity test.

The application accepts Philippine mobile numbers in `09xxxxxxxxx`, `9xxxxxxxxx`, `639xxxxxxxxx` or `+639xxxxxxxxx` form. It normalizes them before sending.

Without a key, messages have status `logged` and no real SMS is sent. With a key, `failed` means the provider rejected the message, the number is invalid, or the request could not complete. `sent` means Semaphore accepted the message; a queued or pending message is not proof of delivery to the phone. Verify final status in Semaphore. The app does not automatically retry failed sends, which avoids duplicate messages and charges after ambiguous timeouts.

Provider reference: https://semaphore.co/docs
