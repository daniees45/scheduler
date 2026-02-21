# Web Architecture Diagrams & Flowcharts

## 1. Complete System Architecture

```
                           INTERNET
                              │
                    ┌─────────┴─────────┐
                    │                   │
        ┌───────────▼────────┐  ┌──────▼──────────┐
        │   VERCEL CDN       │  │  RENDER LIVE    │
        │  (Static Files)    │  │  (Python API)   │
        └──────────┬─────────┘  └────────┬─────────┘
                   │                     │
        ┌──────────▼──────────────────────▼──────┐
        │        BROWSER (Desktop/Mobile)       │
        │                                        │
        │  React.js Application                │
        │  ├─ Dashboard                        │
        │  ├─ Scheduler                        │
        │  ├─ Analytics                        │
        │  └─ Settings                         │
        │                                        │
        │  Redux Store ←─→ React Query         │
        │  Tailwind + Shadcn/ui Components     │
        └──────────┬──────────────────────┬──────┘
                   │ HTTPS/REST           │
    ┌──────────────▼──────────────────────▼──────────┐
    │                                                 │
    │  ┌─────────────────────────────────────────┐  │
    │  │   RENDER.COM (Backend)                  │  │
    │  │                                         │  │
    │  │  FastAPI Application                   │  │
    │  │  ├─ Authentication Routes              │  │
    │  │  ├─ Schedule Management                │  │
    │  │  ├─ Suggestion Engine                  │  │
    │  │  ├─ Quality Prediction (NN)            │  │
    │  │  └─ Analytics API                      │  │
    │  │                                         │  │
    │  │  Services Layer                        │  │
    │  │  ├─ CSP Solver                         │  │
    │  │  ├─ Q-Learner                          │  │
    │  │  ├─ Neural Network                     │  │
    │  │  └─ Productivity Tracker               │  │
    │  │                                         │  │
    │  │  ┌────────────────────────────────┐    │  │
    │  │  │   Redis Cache Layer            │    │  │
    │  │  │   ├─ NN predictions            │    │  │
    │  │  │   ├─ User sessions             │    │  │
    │  │  │   └─ Suggestion cache          │    │  │
    │  │  └────────────────────────────────┘    │  │
    │  │                                         │  │
    │  │  ┌────────────────────────────────┐    │  │
    │  │  │   PostgreSQL Database          │    │  │
    │  │  │   ├─ Users                     │    │  │
    │  │  │   ├─ Events                    │    │  │
    │  │  │   ├─ Suggestions               │    │  │
    │  │  │   ├─ Feedback History          │    │  │
    │  │  │   └─ Model Parameters          │    │  │
    │  │  └────────────────────────────────┘    │  │
    │  │                                         │  │
    │  └─────────────────────────────────────────┘  │
    │                                                 │
    │  External Services                            │
    │  ├─ Auth0 (Optional - JWT Auth)              │
    │  ├─ Sentry (Error Tracking)                  │
    │  └─ AWS S3 (Model Storage - Optional)        │
    │                                                 │
    └─────────────────────────────────────────────────┘
```

---

## 2. Data Flow: User Creates Event

```
┌─────────────────────────────────────────────────────────┐
│  USER INTERACTS: Adds "Study" event 2-3pm Monday      │
└────────────────┬────────────────────────────────────────┘
                 │
                 ▼
        ┌────────────────────┐
        │  React Component   │──────┐
        │  AddEventForm      │      │ validate()
        └────────┬───────────┘      │
                 │                  ◄──────────┘
                 │ (validates)
                 ▼
        ┌────────────────────┐
        │  Redux Store       │
        │ events.push(...)   │
        └────────┬───────────┘
                 │ useEffect
                 ▼
        ┌──────────────────────────────────────┐
        │  Axios HTTP Request                  │
        │  POST /api/schedule/events           │
        │  {                                   │
        │    title: "Study",                   │
        │    day: "Monday",                    │
        │    start: "2:00 PM",                 │
        │    end: "3:00 PM"                    │
        │  }                                   │
        └────────┬─────────────────────────────┘
                 │
     ┌───────────▼────────────── NETWORK ─────────────┐
     │                                                  │
     ▼                                                  │
┌─────────────────────────────┐                       │
│  FastAPI Route Handler      │                       │
│  POST /api/schedule/events  │                       │
└────────┬────────────────────┘                       │
         │                                             │
         ├─ Validate JWT token                        │
         │  └─ get_current_user()                     │
         ├─ Validate request data (Pydantic)          │
         └─ Check Redis cache for user_id             │
             └─ Cache hit? Return cached data         │
         │                                             │
         ▼                                             │
    ┌──────────────────────┐                          │
    │  Database Operation  │                          │
    │  INSERT INTO events  │                          │
    │  WHERE user_id = 123 │                          │
    └────────┬─────────────┘                          │
             │                                         │
             ▼                                         │
    ┌──────────────────────┐                          │
    │  PostgreSQL Process  │                          │
    │  ├─ CREATE event     │                          │
    │  ├─ Get ID (auto)    │                          │
    │  └─ RETURN event     │                          │
    └────────┬─────────────┘                          │
             │                                         │
             ▼                                         │
    ┌──────────────────────┐                          │
    │  Cache Update        │                          │
    │  SET redis[user_123] │                          │
    │  (invalidate old)    │                          │
    └────────┬─────────────┘                          │
             │                                         │
             ▼                                         │
    ┌──────────────────────┐                          │
    │  JSON Response       │                          │
    │  {                   │                          │
    │    id: 456,          │                          │
    │    status: "created" │                          │
    │  }                   │                          │
    └────────┬─────────────┘                          │
             │                                         │
     └───────▼────────────── NETWORK ─────────────────┘
             │
             ▼
        ┌────────────────────┐
        │  React Component   │
        │  Shows success msg │
        │  Refreshes list    │
        └────────┬───────────┘
                 │
                 ▼
        ┌────────────────────┐
        │  User Sees Event   │
        │  in their Schedule │
        └────────────────────┘
```

---

## 3. Suggestions & Learning Flow

```
TIME: User loads "Scheduler" page

┌─────────────────────────────────────────────────────────────┐
│         refresh_personal_lists()                            │
└────────┬────────────────────────────────────────────────────┘
         │
         ├─ GET /api/schedule/events
         │  └─ Load user's events from DB
         │
         ├─ POST /api/suggestions/generate
         │  │
         │  ├─ Extract schedule features (38-dim vectors)
         │  │  └─ num_events, total_hours, gaps, time distribution, etc.
         │  │
         │  ├─ Run CSP Solver (scheduler service)
         │  │  └─ Find available time slots (500ms)
         │  │
         │  └─ Generate suggestions (top 10)
         │
         ├─ Neural Network Quality Assessment
         │  │
         │  ├─ NN Classifier predicts schedule quality
         │  │  ├─ Input: 38-dimension feature vector
         │  │  ├─ Output: ScheduleQuality
         │  │  │  ├─ category: "good"
         │  │  │  ├─ score: 0.75
         │  │  │  └─ confidence: 0.82
         │  │  │
         │  │  └─ Cache result in Redis (1 hour TTL)
         │  │
         │  └─ Augment suggestions with NN scores
         │
         ├─ Q-Learner Ranking (if available)
         │  └─ Re-rank suggestions by user preferences
         │
         └─ Return to frontend
             └─ Display suggestions with:
                ├─ Base score
                ├─ NN quality category
                └─ Accept/Reject buttons

───────────────────────────────────────────────────────────────

USER: Clicks "Accept" on suggestion
         │
         ├─ POST /api/suggestions/{id}/accept
         │
         ├─ Backend: record_user_feedback(...)
         │  │
         │  ├─ Store in bidirectional_feedback.json
         │  │
         │  ├─ Update Q-Learner
         │  │  └─ record_preference(state, action, +1)
         │  │
         │  ├─ Update Neural Network
         │  │  └─ Store as training data
         │  │
         │  └─ Update Productivity Tracker
         │     └─ Log user acceptance
         │
         └─ Return success
             └─ Frontend: Show "Feedback recorded"

───────────────────────────────────────────────────────────────

NEXT TIME: Same user loads scheduler again
         │
         ├─ Features extracted again
         │
         ├─ Q-Learner provides better preferences
         │  (remembers previous acceptances)
         │
         ├─ Neural Network makes better predictions
         │  (trained on user feedback)
         │
         └─ Suggestions prioritized by learned preferences
             └─ System adapts to user!
```

---

## 4. Database Schema Relationships

```
                        USERS
                         │
                    ┌────┼────┐
                    │    │    │
                    ▼    ▼    ▼
                EVENTS SUGGESTIONS FEEDBACK
                
USERS (id, email, password_hash, dept)
  │
  ├─1 to Many─→ EVENTS (id, user_id, title, day, start, end)
  │               │
  │               ├─ Conflicts detected via CSP solver
  │               └─ Used to generate suggestions
  │
  ├─1 to Many─→ SUGGESTIONS (id, user_id, day, start, end, score)
  │               │
  │               └─ NN quality augmented
  │                  └─ NN_PREDICTIONS (category, score, confidence)
  │
  ├─1 to Many─→ USER_FEEDBACK (id, user_id, action, features)
  │               │
  │               └─ Stores "accept"/"reject" + 38-dim features
  │
  └─1 to Many─→ SYSTEM_LOGS (id, user_id, action, timestamp)
                 └─ For monitoring & debugging

INDEXES:
  ├─ users(email) UNIQUE
  ├─ events(user_id, day)
  ├─ suggestions(user_id, created_at)
  └─ feedback(user_id, created_at)
```

---

## 5. API Request/Response Examples

```
REQUEST 1: User Authentication
─────────────────────────────────

POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "secure123"
}

RESPONSE: 200 OK
─────────────────────────────────

{
  "access_token": "eyJhbGciOiJIUzI1NiIs...",
  "token_type": "bearer",
  "user": {
    "id": 123,
    "email": "user@example.com",
    "department": "CS"
  }
}

───────────────────────────────────────────────────────────

REQUEST 2: Generate Suggestions
─────────────────────────────────

POST /api/suggestions/generate
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...
Content-Type: application/json

{
  "schedule_date": "2026-02-13"
}

RESPONSE: 200 OK
─────────────────────────────────

{
  "suggestions": [
    {
      "id": 1,
      "day": "Monday",
      "start_time": "09:00",
      "end_time": "10:00",
      "title": "Study Slot",
      "reason": "2-hour gap available",
      "base_score": 8.5,
      "quality": {
        "category": "good",
        "nn_score": 0.75,
        "confidence": 0.82
      }
    },
    ...more suggestions...
  ],
  "schedule_quality": {
    "category": "fair",
    "overall_score": 0.58,
    "completion_probability": 0.65
  }
}

───────────────────────────────────────────────────────────

REQUEST 3: User Accepts Suggestion
─────────────────────────────────

POST /api/suggestions/1/accept
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...

RESPONSE: 200 OK
─────────────────────────────────

{
  "status": "success",
  "message": "Feedback recorded",
  "learning_update": {
    "q_learner": "preference updated",
    "neural_network": "training sample recorded",
    "productivity": "acceptance logged"
  }
}

───────────────────────────────────────────────────────────

REQUEST 4: Get Quality Assessment
─────────────────────────────────

GET /api/quality/score
Authorization: Bearer eyJhbGciOiJIUzI1NiIs...

RESPONSE: 200 OK
─────────────────────────────────

{
  "category": "fair",
  "overall_score": 0.58,
  "completion_probability": 0.65,
  "conflict_severity": 0.1,
  "suggestions": [
    "Schedule lighter on Mondays",
    "Add 30min break after 2pm classes",
    "Consider moving Tuesday study to morning"
  ],
  "metrics": {
    "events_this_week": 12,
    "total_hours": 18,
    "average_gap": 0.75
  }
}
```

---

## 6. Deployment Pipeline

```
┌─────────────────────────────────────────────────────┐
│         DEVELOPER: git push                         │
│         to main branch                              │
└────────┬────────────────────────────────────────────┘
         │
         ├─ GitHub Actions Triggered
         │
         ├─────────────────────────────────────────┐
         │  FRONTEND DEPLOYMENT (Vercel)           │
         │                                          │
         ├─ npm run build                          │
         │  (React → optimized HTML/CSS/JS)        │
         │                                          │
         ├─ npm run test                           │
         │  (All tests must pass)                  │
         │                                          │
         ├─ Deploy to Vercel CDN                   │
         │  (Global edge locations)                │
         │                                          │
         └─ New URL: ai-scheduler.vercel.app ✓     │
         │                                          │
         │
         ├─────────────────────────────────────────┐
         │  BACKEND DEPLOYMENT (Render)            │
         │                                          │
         ├─ Render detects push                    │
         │                                          │
         ├─ pip install -r requirements.txt        │
         │  (All Python deps)                      │
         │                                          │
         ├─ python -m pytest tests/                │
         │  (All tests must pass)                  │
         │                                          │
         ├─ Build Docker image                     │
         │                                          │
         ├─ Run migrations                         │
         │  alembic upgrade head                   │
         │                                          │
         ├─ Start new container                    │
         │  uvicorn app.main:app                   │
         │                                          │
         └─ Health check: GET /api/health ✓        │
         │                                          │
         │
         └─ Both live! New version available
             Frontend: Updated
             Backend: Updated
             Database: Upgraded (if needed)
```

---

## 7. User Journey Timeline

```
NOVEMBER 2025: Planning Phase
├─ Create implementation plan ✓
├─ Decide on tech stack ✓
├─ Design database schema ✓
└─ Architect API endpoints ✓

DECEMBER 2025: Development Phase
├─ Week 1: Backend foundation
│  ├─ FastAPI setup
│  ├─ Database schema
│  └─ Core endpoints
├─ Week 2: Frontend setup + Integration
│  ├─ React app
│  ├─ UI components
│  └─ API connection
├─ Week 3: Feature completion
│  ├─ AI algorithms integrated
│  ├─ Learning systems active
│  └─ All tests passing
└─ Week 4: Optimization
   ├─ Performance tuning
   ├─ Security hardening
   └─ Documentation

JANUARY 2026: Deployment Phase
├─ Week 1: Staging environment
│  ├─ Deploy to Render (staging)
│  ├─ Deploy to Vercel (staging)
│  └─ User acceptance testing
└─ Week 2: Production launch
   ├─ Data migration
   ├─ Live deployment
   ├─ Beta users invited
   ├─ Monitor for issues
   └─ Celebrate! 🎉

FEBRUARY 2026: Growth Phase
├─ Collect user feedback
├─ Fix reported issues
├─ Optimize performance
├─ Plan Phase 2 features
└─ Scale infrastructure
```

---

## 8. Technology Stack Visualization

```
PRESENTATION LAYER (Browser)
┌─────────────────────────────┐
│ React 18.x + TypeScript     │
│ ├─ React Router (navigation)│
│ ├─ Redux Toolkit (state)    │
│ ├─ React Query (server)     │
│ └─ Tailwind CSS (styling)   │
└────────────┬────────────────┘
             │ HTTPS
             ▼
APPLICATION LAYER (Backend)
┌─────────────────────────────┐
│ Python 3.11 + FastAPI       │
│ ├─ Pydantic (validation)    │
│ ├─ SQLAlchemy (ORM)         │
│ ├─ JWT (authentication)     │
│ └─ Middleware (CORS, etc)   │
└────────────┬────────────────┘
             │ SQL
             ▼
DATA LAYER
┌──────────────────────────────┐
│ PostgreSQL 14+               │
│ ├─ User data                 │
│ ├─ Events & schedules        │
│ ├─ Feedback history          │
│ └─ Model parameters          │
│                              │
│ Redis Cache (Optional)       │
│ ├─ NN predictions            │
│ ├─ User sessions             │
│ └─ Suggestion cache          │
└──────────────────────────────┘

AI SERVICES LAYER (Embedded in Backend)
┌──────────────────────────────┐
│ CSP Solver                   │
│ Q-Learner                    │
│ Neural Network Classifier    │
│ Productivity Tracker         │
│ Bidirectional Feedback       │
└──────────────────────────────┘
```

---

## 9. Performance Target Stack

```
FRONTEND TARGETS
├─ Time to First Byte (TTFB): <200ms
├─ First Contentful Paint: <1.0s
├─ Largest Contentful Paint: <2.5s
├─ Cumulative Layout Shift: <0.1
├─ Time to Interactive: <3.5s
└─ LightHouse Score: >90

BACKEND TARGETS
├─ API response time (p50): <100ms
├─ API response time (p95): <300ms
├─ API response time (p99): <500ms
├─ Database query time: <50ms
├─ Throughput: >1000 req/s
└─ Uptime: >99.5%

FULL STACK
├─ Total page load: <3s
├─ Suggestion generation: <500ms
├─ NN prediction: <100ms
├─ Feedback processing: <50ms
└─ Error recovery: <1s
```

These diagrams can be rendered in tools like Draw.io, Lucidchart, or Mermaid diagram viewers.
