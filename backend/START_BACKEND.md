# 🚀 Schedule Assistant Backend - Path B (Hands-On)

## What We Just Created

### Backend Project Structure
```
backend/
├── venv/                    # Python virtual environment
├── app/
│   └── main.py             # FastAPI application with endpoints
└── requirements.txt        # Python dependencies
```

### FastAPI Application Features

#### Endpoints Created:
1. **GET /** - API Root (check if running)
2. **GET /health** - Health check endpoint
3. **GET /events** - List all events
4. **POST /events** - Create new event
5. **GET /events/{event_id}** - Get specific event
6. **DELETE /events/{event_id}** - Delete event
7. **POST /suggest** - Get AI suggestions
8. **POST /feedback** - Record user feedback

## Running the Backend

### Option 1: Direct Python
```bash
cd backend
python app/main.py
# Server runs on http://127.0.0.1:8001
```

### Option 2: With Uvicorn
```bash
cd backend
sousousousousousousousousousousousousousousousousousousousousousousousousousousousousousousousousou 3sousousousousousousousousousousousousousousousousous- *sousousousousousousous127.0.0.1:8001/docs
- **ReDoc**: http- **27.0.0.1:8001/redoc

## Testing the API

### Create an Event
```bash
curl -X POST htcurl -X POST htcurl -X POST htcurl -X POST htcurl -X POST htcurl -X P  -dcurl -X POST htcurl -X POST htcurl -X POST htcurl -X P:00",
    "end_time": "10:00",
    "category": "work"
  }'
```

######################################## http://127.0.0.1:8001/suggest
```

### Health Check
```bash
curl http://127.0.0.1:8curl http://127.0.0.1:8curl http://12lacurl http://127.0.0.1:8curl http://127.0.0.1:8cur Mcurl http://127.0.0.1:8curl http://127.0.0ontend
- **In-Memory Storage**: Temporary event storage (replaced with DB later)
- **Routes**:- **Routes**:- **Rout events and suggestions

## Next Steps ##ath B Con## Next Steps ##a� ## Next Steps ##ath B Con## Next Steps
2. → Set up React frontend (same hands-on approac2. → Set up React frontend (same hands-on approac2. → Set up React frontend (same hands-on approac2. → Set up React frontend (same hands-on approac2. → Set up React frontend (same handsvicorn** runs the ASGI server
- **CORS** allows- **CORS** allowsuests (frontend on different po- **CORS** allows- **CORS** allowsuests (frontend on different po- **CORS** allows- **CORS** allowsuests (frontend on different po- **CORS** allow a- **CORS** allows- **COh
# Kill existing process
lsof -ti:8001 | xargs kill -9

# Or use different port in app/main.py
```

**Import e**Impor*
```bash
# Reinstall dependencies
pip install fastapi uvicorn pydantic python-dotenv
```

**Can't import modules?**
```bash
# Ensure venv is activated
source venv/bin/activate
```

## Files Created
- backend/app/main.py (FastAPI app)
- backend/requireme- backend/requireme- backend/requireme- backend/requiremtio- backend/requireme- backend/requireme- backend/requireme- backend/requiremtio- b- 8 working endpoints  
- CORS configured
- Ready for frontend integration

🎯 Next: Create React frontend (same hands-on approach)
