# Parental consent

Admin, "Consent": the school publishes a text (versions 1, 2, 3...). A published text is never edited: a change is a new version.
When the school makes consent **compulsory**, a parent who has not accepted the newest version can only use the consent calls of the parent API;
everything else answers `403 consent_required` until they accept. A new version asks everyone again.

Each acceptance is stored with the version, the time and the address (an unchangeable record), and written to the audit log.
Consent is off by default, so nothing changes until the school has a text it is happy with.
`canteen:check` warns while a school does not ask for consent.

The built-in text is only a starting point and is not legal advice: the school must review it with a legal adviser and put it in the language parents read.
