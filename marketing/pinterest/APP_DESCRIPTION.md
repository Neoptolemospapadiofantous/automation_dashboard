# Pinterest developer app: description and form answers

Paste these into the app's page at https://developers.pinterest.com/apps/ when
applying for Standard access. Every statement here matches what
`post_due_pins.py` actually does. If the code changes, update this file too.

---

## App name

Crispfolk Pin Scheduler

## Company / website

Crispfolk: https://crispfolk.com

## Privacy policy URL

https://crispfolk.com/privacy

(Publish `site/privacy-policy.html` at that address **before** submitting.)

## App description (paste as-is)

> Crispfolk Pin Scheduler is a private, internal tool that publishes Crispfolk's
> own product pins to Crispfolk's own Pinterest business account. It is not a
> public app: no other Pinterest users can sign in to it, and it never accesses
> anyone else's account or data.
>
> What it does:
> - Publishes pre-written pins for products sold on crispfolk.com (starting with
>   the Clean Counter Kit) on a fixed schedule, up to 10 pins per day at set
>   times. Each pin uses Crispfolk's own product photo, a title, a description
>   and a link to the product page on crispfolk.com.
> - Reads the list of Crispfolk's own boards, so the operator can choose which
>   boards the pins are published to.
> - Reads the most recent pins on those boards to check whether a scheduled pin
>   has already been published, so the same pin is never posted twice.
>
> What it does not do: it does not read, follow, save, comment on or message
> other users' content; it does not collect personal data from Pinterest users;
> it does not access ads, analytics or audience data; and it does not sell or
> share any data.
>
> How it runs: a scheduled job on GitHub Actions runs a short Python script at
> each posting time, calls the Pinterest API v5 (POST /v5/pins,
> GET /v5/boards, GET /v5/boards/{board_id}/pins) and exits. OAuth tokens are
> stored only as encrypted GitHub Actions secrets in Crispfolk's repository,
> used only to call the Pinterest API, and never logged or shared.

## Scopes requested (and why)

| Scope | Why it's needed |
| ----- | --------------- |
| `boards:read` | List Crispfolk's own boards to pick where pins are published |
| `pins:read` | Read recent pins on those boards to avoid posting a duplicate |
| `pins:write` | Create the scheduled product pins |

No other scopes are requested. In particular, it doesn't use `user_accounts:read`,
`ads:*`, `catalogs:*` or any write access to boards.

## Redirect URI

`https://localhost/`, used once by the account owner to authorize the app and
receive the refresh token (see README, setup step 2). No public sign-in page exists.

## Who uses it

Only the Crispfolk account owner. One Pinterest account, no end users.
