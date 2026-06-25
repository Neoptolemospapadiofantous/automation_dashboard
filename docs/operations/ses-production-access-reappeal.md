# SES production-access reappeal (eu-central-1)

Submit this as a reply **in the same AWS Support case** that was denied on
2026-06-23 (do not open a new case).

---

Hello,

Thank you for the review. We'd like to provide additional specifics and request
a re-evaluation of production access for our account in eu-central-1.

**Verified identities**

We operate two verified domain identities in eu-central-1, both with Easy DKIM
enabled:

- `txn.flowstack.run` — DKIM verified, custom MAIL FROM `bounce.txn.flowstack.run`
  with SPF (`v=spf1 include:amazonses.com ~all`) and feedback MX.
- `app.flowstack.run` — our primary application domain, DKIM enabled.

We send only from `noreply@txn.flowstack.run` and `noreply@app.flowstack.run`.

**What we send (transactional only)**

Flowstack is a SaaS customer-support automation platform (https://app.flowstack.run).
Every email is transactional and triggered by an individual user action:

1. Email-address verification — sent immediately when a user registers, with a
   signed confirmation link.
2. Password reset — sent only when a user requests it from the login page.
3. In-app notifications — e.g. "a new lead was assigned to you," sent to the
   specific team member who owns the record.
4. Waitlist confirmation — a single double-opt-in confirmation when someone joins
   our pre-launch waitlist.

We send no marketing, newsletters, or bulk campaigns from these identities.

**Frequency and volume**

Low and event-driven. We currently send well under 100 emails per day and do not
anticipate exceeding ~500 per day in the near term. There are no scheduled blasts —
every message is one-to-one, triggered by a user action.

**Recipient lists**

We do not maintain, import, or purchase mailing lists. Every recipient is a person
who just performed an action on our own site (registered, requested a reset, was
assigned a record, or explicitly opted into the waitlist). No scraped or
third-party lists are used.

**Bounces, complaints, and unsubscribes**

We rely on the SES account-level suppression list, so addresses that hard-bounce or
complain are automatically suppressed from future sends. Our custom MAIL FROM
(`bounce.txn.flowstack.run`) routes bounce and feedback messages back to SES, and we
have an SES configuration set with SNS notifications wired to monitor bounce and
complaint events and remove problem addresses promptly. As these are essential
account and transactional messages, formal unsubscribe does not apply to
verification and password-reset email; for in-app notification email, users can
disable email notifications in their account settings.

**Example content**

Verification email — Subject: "Verify your email to activate Flowstack". Body:
"Hi {name}, Welcome to Flowstack. One quick step before you can use the dashboard:
confirm this is your email. [Verify email] If you didn't sign up, you can safely
ignore this email — the link will expire automatically." Password-reset and
lead-assignment emails follow the same concise, branded, single-purpose format with
no promotional content.

We're happy to provide additional samples on request.

Thank you,
Flowstack
