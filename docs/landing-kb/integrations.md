# Integrations and channels

## Where the widget runs

The chat widget is one script tag. It goes on any website: Shopify, WordPress,
Wix, Webflow, Squarespace, React/Next.js, plain HTML — any domain or subdomain.
If you can edit your site's HTML or theme, you can install it, and we help if
you get stuck. There is also a hosted chat page: a Flowstack URL you can link
to, with nothing to install.

## What ships out of the box

Captured leads and full transcripts land in the real-time Flowstack dashboard,
and your team gets email alerts for new leads and human-handoff requests. The
agent answers from the knowledge you upload (docs, FAQs, site pages). There is
no public API today.

## Webhooks — push leads to Zapier, Make, Google Sheets or your CRM

On any paid plan (Starter and up) the dashboard sends outbound webhooks:
Settings → Webhooks, add the URL of your Zapier or Make trigger, a Google
Sheets connector or your own endpoint, and pick the events. Three events
exist: a new lead is captured, a visitor asks for a human, a conversation
ends. Each delivery is a signed JSON POST (HMAC-SHA256 with a secret shown
once when you create the endpoint), retried twice if your endpoint is down,
and you can send a test event from the page. That is how self-serve
customers get leads into HubSpot, Pipedrive, Salesforce, a spreadsheet or a
Slack channel without a build. There are no pre-built connectors to click
— the webhook is the connector, and Zapier or Make does the mapping.

## Connecting your own tools — CRM, calendars, telephony

Wiring that goes beyond a webhook is build work: two-way CRM sync, calendar
booking, telephony, helpdesks, internal databases. Fixed scope, usually 4–6
weeks, and you keep the code. The way in is the free 30-minute call at
flowstack.run/audit — a written price within 48 hours, yours to keep either
way.

When a visitor asks about connecting a specific tool: if it accepts a webhook
or has a Zapier/Make trigger, say so and point at Settings → Webhooks on a
paid plan; otherwise confirm that exact wiring is what a custom build
delivers, then ask for their email so the team can map their stack with them.

## Channels

Web chat only today: the website widget and the hosted chat page. No WhatsApp,
phone/voice or SMS off the shelf — those are scoped as custom build work.

## Returning visitors

The chat resumes for a returning visitor on the same browser and device: the
conversation history is kept, and the agent picks up where it left off.
