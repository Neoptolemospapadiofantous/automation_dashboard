Hello,

Thank you. Below is the detail on our sending practices.

Verified identity

We have a verified domain identity, txn.flowstack.run, in eu-central-1, with DKIM enabled and verified and a custom MAIL FROM (bounce.txn.flowstack.run) configured with the required SPF record (v=spf1 include:amazonses.com ~all) and feedback MX. We send only from noreply@txn.flowstack.run.

What we send (transactional only)

Flowstack is a SaaS customer-support automation platform (https://app.flowstack.run). All email is transactional and triggered by an individual user action:

1. Email-address verification - sent immediately when a user registers, with a signed confirmation link.
2. Password reset - sent when a user requests it from the login page.
3. In-app notifications - for example "a new lead was assigned to you," sent to the specific team member.
4. Waitlist confirmation - a single double-opt-in confirmation when someone joins our pre-launch waitlist.

We send no marketing or bulk email from this identity.

Frequency and volume

Low and event-driven. At launch we expect well under 100 emails per day, growing gradually with signups; we do not anticipate exceeding around 1,000 per day in the near term. There are no scheduled blasts - every message is one-to-one, triggered by a user.

Recipient lists

We do not maintain or purchase mailing lists. Every recipient is a person who just performed an action on our own site (registered, requested a reset, or explicitly opted into the waitlist). There are no imported, scraped, or third-party lists.

Bounces, complaints, and unsubscribes

We rely on the SES account-level suppression list so that addresses which hard-bounce or complain are automatically suppressed from future sends. Our custom MAIL FROM (bounce.txn.flowstack.run) routes bounce and feedback messages back to SES, and we are configuring an SES configuration set with SNS notifications to monitor bounce and complaint events and remove problem addresses promptly. As these are essential account and transactional emails, formal unsubscribe does not apply to verification and password-reset messages; for in-app notification emails, users can disable email notifications in their account settings.

Example content

Verification email - Subject: "Verify your email to activate Flowstack". Body: "Hi {name}, Welcome to Flowstack. One quick step before you can use the dashboard: confirm this is your email. [Verify email] If you didn't sign up, you can safely ignore this email - the link will expire automatically." Password-reset and lead-assignment emails follow the same concise, branded, single-purpose format with no promotional content.

We are happy to provide additional samples on request.

Thank you,
Flowstack
