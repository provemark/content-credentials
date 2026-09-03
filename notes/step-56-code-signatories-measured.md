# Step 56 — Who signed the Code of Practice, and where the PHP is (2026-09-03)

SPEC-034's second trigger is *"a deliberate decision that this package targets
Code of Practice signatories"*. That decision was never grounded in who those
signatories are. This step grounds it, so the next person does not have to.

## The list, from the source

The AI Office's [Code of Practice on Transparency of AI-generated
Content](https://digital-strategy.ec.europa.eu/en/policies/code-practice-ai-generated-content)
has **two sections, signed separately**:

| section | subject | signatories |
|---|---|---|
| 1 — providers | marking and detection of AI-generated content | 82 (83 rows parsed) |
| 2 — deployers | labelling deepfakes and AI-generated text | 152 |

About 190 organisations in total by 31 July 2026; roughly half are small and
recent companies. Section 1 may also be signed by providers of marking and
detection solutions. Named examples for section 1: Aleph Alpha, Anthropic, Black
Forest Labs, Cohere, Google, Meta, Microsoft, Mistral, OpenAI, Synthesia. For
section 2: Bulgari, Fastweb, Getty Images, Iberdrola, Lenovo, Lufthansa.
Signatory task forces launched in September 2026.

**45 of the 83 section-1 rows also appear in section 2.** So section 1 is not
purely model vendors; more than half of it is smaller European companies that
touch both obligations.

⚠️ The list is in the static HTML of the [announcement
page](https://digital-strategy.ec.europa.eu/en/news/strong-backing-code-practice-transparency-ai-generated-content),
in an `ecl-table`. An earlier grep for individual company names returned nothing
and was read as "the list is loaded dynamically" — wrong twice over: the list was
there, and the absence of those names *was* the finding. Grepping for names you
expect proves nothing when the hypothesis is that they are absent.

## Where the PHP is, measured

Ten signatories were probed by fetching their sites and looking for CMS
fingerprints (`wp-content`, `Drupal.settings`, `typo3conf`) and `X-Powered-By`:

| signatory | section | result |
|---|---|---|
| **Guadaltel** (ES, IT services) | **1 — marking** | **WordPress, PHP 8.5.10** |
| Flipsnack, Inetum, Barcelona Provincial Council, ContentLens.ai | 1 | no CMS fingerprint |
| Antinews.gr | 2 | WordPress |
| Dealnews.gr | 2 | WordPress, PHP 8.4.25 |
| Estianews.gr | 2 | WordPress |
| Alter Ego Media (GR, publisher) | 2 | WordPress |
| PIA Media | 2 | none |

**The PHP sits in section 2, and section 2 is not what this package does.**
Section 2 is labelling — a visible disclosure on deepfakes and AI text.
This package implements layer 1 of section 1: signed, timestamped provenance
metadata. The one PHP fingerprint found in section 1 is an IT services company.

Two limits on that, or it reads stronger than it is:

- A company's marketing site running WordPress says nothing about the pipeline
  that generates and would mark its AI content. Guadaltel's *site* is PHP; their
  marking path may be anything.
- Ten of about 190, chosen by the shape of their names. A biased sample, not a
  survey.

## What follows for SPEC-034

Nothing that supports approving it. "We target Code signatories" sounds like one
audience and is two, and the half whose language and stack match this package is
the half whose obligation it does not implement. Worse, those WordPress
publishers are the group NOTES Step 24 already put out of reach: shared hosting
runs neither a second process nor a native extension, so even where the audience
fits, the delivery shape does not.

The trigger stands unfired, and now for a measured reason rather than an
unexamined one.
