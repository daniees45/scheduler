from flask_sqlalchemy import SQLAlchemy
from flask_login import UserMixin
from werkzeug.security import generate_password_hash, check_password_hash

db = SQLAlchemy()

class User(UserMixin, db.Model):
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(64), unique=True, nullable=False)
    email = db.Column(db.String(120), unique=True, nullable=False)
    password_hash = db.Column(db.String(128))
    role = db.Column(db.String(20), nullable=False, default='student')
    
    # Roles: 'super_admin', 'faculty_admin', 'lecturer', 'student'

    def set_password(self, password):
        self.password_hash = generate_password_hash(password)

    def check_password(self, password):
        return check_password_hash(self.password_hash, password)

    @property
    def is_admin(self):
        return self.role in ['super_admin', 'faculty_admin']
    
    @property
    def is_super_admin(self):
        return self.role == 'super_admin'

    def __repr__(self):
        return f'<User {self.username} - {self.role}>'

class Course(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    code = db.Column(db.String(20), unique=True, nullable=False)
    title = db.Column(db.String(100), nullable=False)
    level = db.Column(db.Integer, nullable=False) # 100, 200...
    department = db.Column(db.String(50))
    credit_hours = db.Column(db.Integer, default=3)

class Room(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(20), unique=True, nullable=False)
    capacity = db.Column(db.Integer)
    type = db.Column(db.String(20)) # 'Lecture', 'Lab'

class LecturerAvailability(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    lecturer_name = db.Column(db.String(64), nullable=False)
    day = db.Column(db.String(10), nullable=False) # Monday, Tuesday...
    is_available = db.Column(db.Boolean, default=True)
    # Could add time slots here for more granularity

class ScheduleEvent(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    course_code = db.Column(db.String(20))
    course_title = db.Column(db.String(100))
    lecturer = db.Column(db.String(64))
    room = db.Column(db.String(20))
    day = db.Column(db.String(10))
    start_time = db.Column(db.String(10))
    end_time = db.Column(db.String(10))


class PersonalEvent(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    title = db.Column(db.String(120), nullable=False)
    day = db.Column(db.String(10), nullable=False)
    start_time = db.Column(db.String(10), nullable=False)
    end_time = db.Column(db.String(10), nullable=False)
    event_type = db.Column(db.String(20), default='personal')
    source = db.Column(db.String(20), default='manual')

    user = db.relationship('User', backref=db.backref('personal_events', lazy=True))

