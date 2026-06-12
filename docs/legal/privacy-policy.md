# Privacy & Cookie Policy — draft for counsel

> **NOT LEGAL ADVICE — draft.** This is the missing artifact the rest of
> the set presupposes: the trust page and ToS both reference a "Privacy
> Policy and Cookie Policy" that did not exist. It covers the role the
> other documents do NOT: **[Company Name] as data CONTROLLER** for its
> own customers (dashboard accounts, billing) — distinct from the
> processor role covered by the DPA/trust page (clients' end-users).
> Complete `[PLACEHOLDERS]`; counsel review required before publication.

---

## Privacy Policy

**Who we are.** [Company Name] Ltd, [address, registration no.],
("we"). Contact: [privacy@…]. [DPO if appointed.]

**The two hats we wear:**

1. **Controller** — for data about *you*, our customer: your account,
   team, and billing data. This policy covers that.
2. **Processor** — for data about *your website visitors* who chat with
   your assistant. Your business is the controller; our Data Processing
   Agreement governs, and our [Trust page] explains it.

### What we collect as controller, and why

| Data | Purpose | Lawful basis (Art. 6) | Retention |
|---|---|---|---|
| Name, email, password (hashed) | Account + authentication | Contract (1(b)) | Account life + [statutory limitation period] |
| Email verification + login metadata | Security | Legitimate interest (1(f)) | [period] |
| Team, agent configuration, knowledge-base content you upload | Providing the service | Contract | Account life; deleted with the agent/account |
| Billing identity, subscription + credit history | Charging you; tax/accounting | Contract; legal obligation (1(c)) | [statutory accounting period — Cyprus: state] |
| Usage metering (per-message credits, token rollups) | Billing integrity, abuse prevention | Contract; legitimate interest | [period] |
| Support correspondence | Helping you | Contract / legitimate interest | [period] |

**What we do NOT do:** sell personal data; use your data or your
visitors' conversations to train AI models ([verify per provider DPA —
our model providers are bound not to train on API data; Google's
free tier is contractually excluded for this reason]); automated
decisions with legal effect about you.

### Payment data

Card details go directly to Stripe; we never see or store card numbers.
Stripe acts as [independent controller/processor — confirm
characterization with counsel] for payment processing.

### Recipients

Our sub-processors (current list: [link to trust-page table] —
Anthropic, OpenAI[, Google], Stripe, Pusher, AWS). Authorities where
legally required. No other third parties.

### Transfers

Some providers process data in the United States — safeguards:
Standard Contractual Clauses + Transfer Impact Assessment
[verify per provider; see claims-vs-reality.md].

### Your rights (GDPR Arts. 15–22)

Access, rectification, erasure, restriction, portability, objection;
withdraw consent where consent is the basis; complain to the Cyprus
Commissioner for Personal Data Protection (dataprotection.gov.cy) or
your local supervisory authority. Contact [privacy@…] — we respond
within one month.

### Security

Summarised on our [Trust page] §7; technical detail available to
customers under NDA. Breach notification per GDPR Arts. 33–34.

---

## Cookie Policy

| Cookie | Surface | Type | Lifetime | Purpose |
|---|---|---|---|---|
| Laravel session + XSRF | Dashboard | Strictly necessary | Session | Login state, CSRF protection |
| `fs_embed_{slug}` | Embedded chat widget | Strictly necessary (functional) | 30 days | Keeps a visitor's conversation continuous across page loads. No tracking, no advertising, not read across sites |

We set **no** analytics, advertising, or cross-site tracking cookies.
Because every cookie above is strictly necessary for the requested
service, no consent banner is required [confirm position with counsel
under ePrivacy / Cyprus implementing law — the 30-day widget cookie is
the item to validate].

If non-essential cookies are ever added, a consent mechanism ships
first ([cookie settings link]).

---

## Pre-publication checklist

- [ ] Retention periods filled with real numbers (accounting law!)
- [ ] Stripe controller/processor characterization confirmed
- [ ] Provider no-training commitments verified against current DPAs
- [ ] Widget-cookie "strictly necessary" position confirmed (ePrivacy)
- [ ] Wire into the product: enable `Features::termsAndPrivacyPolicy()`
      (Jetstream) so registration requires acceptance and the policy
      pages render — **only after counsel approval**, never with draft text
- [ ] Counsel review completed
