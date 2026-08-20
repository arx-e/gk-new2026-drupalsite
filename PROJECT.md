## Drupal 11 Assessment System — Full Structure Summary

---

### Project Context

A criteria-based accreditation assessment system (Green Key) where establishments apply for certification by responding to a structured set of criteria, reviewed by programme admins, auditors and an international jury.

---

### Taxonomies

#### `criteria_categories`
Two-level hierarchy used to organise criteria.

| Field | Type | Notes |
|---|---|---|
| `name` | Text | Built-in |
| `field_category_code` | Plain text | e.g. "1", "1.2" |
| `description` | Long text | Built-in |
| `parent` | Taxonomy parent | Built-in — used for hierarchy |

- 7 top-level categories
- 17 sub-categories as child terms

#### `establishment_types`
6 establishment types with short codes.

| Field | Type | Notes |
|---|---|---|
| `name` | Text | Full name |
| `field_establishment_type_code` | Plain text | HH, CHP, SA, CC, R, A |

#### `admin_regions`
Greek administrative regions and regional units (Περιφέρειες και περιφερειακές ενότητες). Referenced by `field_dioikitiki_enotita` on the establishment node.

#### `nomoi`
Greek prefectures / counties (Νομοί). Referenced by `field_est_nomos` on the establishment node.

#### `application_cycles`
Application cycles. Referenced by `field_app_cycle` on the `application_container` node.

#### `metrics_type`
Metric types used by performance data fields.

#### `tags`
General-purpose tags vocabulary.

---

### User Roles

| Role | Permissions Summary |
|---|---|
| **Establishment Admin** | est_admin | Fills in criterion responses, uploads files/photos, adds own notes |
| **Programme Operator Green Key** | green_key | Manages applications, assigns auditors, adds programme notes |
| **Auditor** | Sets compliance status per criterion, adds auditor notes |
| **Certification Body** | certification_body | Adds jury notes, view access |
| **Site Admin** | administrator |  Full access, runs setup batch process |

---

### Entity Types

#### 1. `establishment` — Node Type

| Field Title | Field Name | Type |
|---|---|---|
| Title | `title` | Node title |
| Establishment Type | `field_est_type` | Entity ref → `establishment_types` taxonomy |
| Establishment name (EN) | `field_est_name_en` | Text |
| Establishment name (GR) | `field_est_name_gr` | Text |
| Διεύθυνση (Οδός, αριθμός, τοποθεσία) (Address: street, number, location) | `field_est_address` | Text |
| Διεύθυνση Πόλη (Address: city) | `field_est_address_city` | Text |
| Διεύθυνση - Ταχυδρομικός Κώδικας (Address: postal code) | `field_est_address_postcode` | Text |
| Νομός (Prefecture / county) | `field_est_nomos` | Entity ref → `nomoi` taxonomy |
| Διοικητική Ενότητα (Administrative region / unit) | `field_dioikitiki_enotita` | Entity ref → `admin_regions` taxonomy |
| Location | `field_est_location` | Geofield |
| Website | `field_est_website` | Link |
| Establishment Admin | `field_est_admin_user` | Entity ref → User (multi) |
| Ιδιοκτήτης μονάδας (Establishment owner) | `field_est_owner` | Text |
| Διευθυντής μονάδας (Establishment manager) | `field_est_manager_name` | Text |
| Email διευθυντή (Manager email) | `field_est_manager_email` | Email |
| Τηλέφωνα επικοινωνίας (διευθυντή/επιχειρηματία) (Manager/owner telephone numbers) | `field_est_phones_manager_owner` | Text |
| Ονοματεπώνυμο του υπεύθυνου για το GreenKey (Green Key contact name) | `field_est_gk_contact_name` | Text |
| Θέση του υπεύθυνου για το GreenKey (Green Key contact position) | `field_est_gk_contact_position` | Text |
| Email επικοινωνίας για το Greenkey (Green Key contact email) | `field_est_gk_contact_email` | Email |
| Τηλέφωνο επικοινωνίας για το GreenKey (Green Key contact phone) | `field_est_gk_contact_phone` | Text |
| Αριθμός δωματίων (Number of rooms) | `field_est_rooms_number` | Integer |
| Αριθμός Εργαζομένων (Number of employees) | `field_est_employees` | Integer |
| Αριθμός επισκεπτών / έτος (προηγούμενο) (Visitors per year, previous year) | `field_est_visitors_year` | Text |
| Αριθμός διανυκτερεύσεων / έτος (προηγούμενο) (Nights per year, previous year) | `field_est_nights_year` | Text |
| Λειτουργία (Operation) | `field_est_seasonal` | List: seasonal or year-round |
| Λειτουργία εποχική: Περίοδος λειτουργίας (Seasonal operation: operating period) | `field_est_periodos_leitoyrgias` | Date range |
| Υπάγεται η μονάδα σε περιοχή ή ζώνη ειδικής προστασίας; (Is the establishment in a protected area or zone?) | `field_est_protected_area` | Boolean |
| Περιοχή ή ζώνη ειδικής προστασίας στη οποία υπάγεται η μονάδα (Name of protected area or zone) | `field_est_protected_area_name` | Text |

---

#### 2. `criterion` — Node Type
139 criteria imported via CSV migration.

| Field Title | Field Name | Type |
|---|---|---|
| Title | `title` | Node title |
| Criterion Category | `field_criterion_category` | Entity ref → `criteria_categories` (top level) |
| Criterion Subcategory | `field_criterion_subcategory` | Entity ref → `criteria_categories` (child) |
| Criterion Code | `field_criterion_code` | Plain text (e.g. 101) |
| Criterion Code Alt | `field_criterion_code_alt` | Plain text (e.g. 1.01) |
| Relevance | `field_expl_relevance` | Formatted long text |
| Expectations | `field_expl_expectations` | Formatted long text |
| Audit Evidence | `field_expl_audit_evidence` | Formatted long text |
| Imperative For | `field_imperative_for` | Entity ref → `establishment_types` (multi) |
| Guideline For | `field_guideline_for` | Entity ref → `establishment_types` (multi) |
| Files Required | `field_cr_upload_files` | Boolean |
| Photos Required | `field_cr_upload_photos` | Boolean |
| Performance Data Required | `field_cr_performance_data` | Boolean |

> **Note:** `field_imperative_for` ∪ `field_guideline_for` defines which establishment types the criterion applies to. Each criterion can be Imperative for some types and Guideline for others.

---

#### 3. `application_container` — Node Type (Assessment Container)
One per establishment per cycle. Acts as the folder holding all criterion responses.

| Field Title | Field Name | Type | Notes |
|---|---|---|---|
| Title | `title` | Node title | Auto-generated e.g. "Establishment X — 2025" |
| Establishment | `field_app_establishment` | Entity ref → Establishment node | |
| Application Cycle | `field_app_cycle` | Entity ref → `application_cycles` taxonomy | Present in configuration |
| Application State | (none — `moderation_state`) | Content Moderation | Provided automatically by the `application_review` workflow — do NOT add a custom field |
| Submitted Date | `field_app_date_submitted` | Date | Planned — not yet in configuration; set on submission |
| Audited Date | `field_app_date_audited` | Date | Planned — not yet in configuration |
| Certification Date | `field_app_date_certified` | Date | Planned — not yet in configuration |
| Certification Valid For | `field_app_cert_range` | Date range | Planned — not yet in configuration |
| Score | `field_app_score` | Decimal | Planned — not yet in configuration; calculated — TBD |
| Programme Operator | `field_app_operator_user` | Entity ref → User | Planned — not yet in configuration |
| Auditor | `field_app_auditor_user` | Entity ref → User (multi) | Planned — not yet in configuration |
| Certifying Body User | `field_app_certif_user` | Entity ref → User (multi) | Planned — not yet in configuration |
| Comments | `field_app_comments` | Core Comments | Planned — not yet in configuration; all roles |

---

#### 4. `criterion_response` — ECK Custom Entity (core workhorse)
One per criterion per application. Created in bulk at setup time.

| Field Name | Type | Role Access | Notes |
|---|---|---|---|
| `field_res_application` | Entity ref → Application node | System | |
| `field_res_criterion` | Entity ref → Criterion node | System | |
| `field_res_criterion_type` | List (Imperative / Guideline) | System | **Snapshotted at setup** |
| `field_res_answer` | List (Yes / No / Partial) | Establishment admin | |
| `field_res_uploads_files` | File multi (pdf, doc, docx) | Establishment admin | Only shown if `field_cr_upload_files = TRUE` |
| `field_res_uploads_photos` | Image multi | Establishment admin | Only shown if `field_cr_upload_photos = TRUE` |
| `field_res_performance_data` | Custom Field module (tabular) | Establishment admin | Only shown if `field_cr_performance_data = TRUE` |
| `field_res_compliance_status` | List (Compliant / Partial / Non-compliant / None) | Auditor only | |
| `field_res_note_establishment` | Long text | Establishment admin | |
| `field_res_note_programme_op` | Long text | Programme admin | |
| `field_res_note_auditor` | Long text | Auditor | |
| `field_res_note_jury` | Long text | Jury | |

> **Key design decision:** `field_res_criterion_type` is resolved and stored at setup time from the criterion's `field_imperative_for` / `field_guideline_for` fields against the establishment's type. This ensures historical accuracy — if a criterion's classification changes in a future cycle, past responses retain their original classification.

> **ECK revision note:** ECK revisions are functional but the browsing UI is still in development ([issue #3376678](https://www.drupal.org/project/eck/issues/3376678)). Mitigate with `changed` timestamp + `uid` fields for basic traceability.

---

### Application Workflow (Content Moderation)

Handled by the `application_review` workflow (`content_moderation`). The state is stored in the built-in `moderation_state` base field on the application node — no custom `field_app_state` field is required (see the note in the `application_container` table above).

```
draft → submitted → under_review → under_audit → finalized
```

Plus an optional `published` state for publishing finalized results.

| Transition | From → To | Triggered by |
|---|---|---|
| `submit_application` | draft → submitted | Establishment admin |
| `put_under_review` | submitted / under_review → under_review | Programme admin / Auditor |
| `put_under_audit` | submitted / under_review → under_audit | Programme admin / Certifying body |
| `finalize` | finalized / under_audit → finalized | Programme admin / Certifying body |
| `create_new_draft` | draft / published → draft | Programme admin (to return for corrections) |
| `publish` | draft / published → published | Programme admin / Site admin |

Each transition creates a revision with a log message. Use **Content Moderation Notifications** contrib module for email alerts on transitions.

**Access split:**
- Who may move states → content moderation transition permissions (`use application_review transition <name>`) per role.
- Who may edit fields per state → custom entity access handler + field_permissions (e.g. establishment admins edit only while in `draft`).

---

### Module Stack

#### Core (no contrib needed)
`taxonomy`, `comment`, `content_moderation`, `workflows`, `file`, `media`, `views`, `field_permissions` *(contrib but essential)*

#### Contrib — Essential
| Module | Purpose |
|---|---|
| **ECK** | Criterion Response custom entity |
| **Field Permissions** | Per-field role-based access |
| **Inline Entity Form** | Edit responses inline in accordion UI |
| **Migrate + Migrate Plus + Migrate Tools + Migrate Source CSV** | Criteria import |
| **Custom Field** | Performance data tabular input |
| **Pathauto** | Clean URLs |

#### Contrib — Recommended
| Module | Purpose |
|---|---|
| **Gin** (admin theme) | Better UX for complex data entry |
| **Content Moderation Notifications** | Email on state transitions |
| **Views Bulk Operations** | Batch actions on applications |
| **Diff** | Visual field-level revision comparison |
| **Group** *(optional)* | Scope establishment admins to their own data |

---

### Setup Batch Process (per new application)

When a programme admin opens a new cycle for an establishment:

1. Create one `application_container` node referencing the establishment + cycle
2. Determine establishment type from `field_est_type`
3. Query all `criterion` nodes where `field_imperative_for` OR `field_guideline_for` includes that establishment type
4. For each matching criterion, create one `criterion_response` ECK entity:
    - Set `field_res_application` → new `application_container`
   - Set `field_res_criterion` → criterion node
   - Set `field_res_criterion_type` → resolve Imperative or Guideline for this establishment type
5. All responses initialised with no answer

This runs as a **custom admin form/action** at e.g. `/admin/assessment/new` triggering a Drupal Batch API operation.

---

### Planned UI (to be detailed later)

A **custom route + controller** at `/application/{application}/respond` rendering:
- Accordion by category → sub-category → criterion
- Each criterion row expands to show the inline response form (IEF) with role-appropriate fields
- Sidebar block with live stats (answered/total per category, compliance breakdown)
- Progress indicators per category/sub-category
