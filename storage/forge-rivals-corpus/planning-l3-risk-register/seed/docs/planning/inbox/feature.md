# Feature spec — Self-Serve Tenant Onboarding

## Objective
Allow a new tenant admin to provision their workspace from a public
landing page without operator intervention: pick a slug, verify email,
seed a baseline workspace, and bill against a Stripe customer.

## Stakeholders
- Growth team (lead: avery)
- Billing platform (lead: kim)
- Security (lead: rafa)
- SRE (lead: sasha)

## Scope
1. Public signup form (anonymous traffic).
2. Email verification with 24h expiring token.
3. Stripe customer creation + setup intent.
4. Baseline workspace seeding (5 demo notes, 1 admin user, default
   automation policy = paused).
5. Welcome email + handoff to dashboard.

## Risk surfaces to size
- Abuse: bots provisioning many tenants.
- Stripe outage during signup.
- Slug collisions in concurrent signups.
- Workspace seed corrupting on partial write.
- PII exposed through verification email link.
- Pricing change mid-funnel.
