import express from 'express';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import { DatabaseSync } from 'node:sqlite';
import { GoogleGenAI, Type } from '@google/genai';

dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = Number(process.env.PORT) || 3000;
const database = new DatabaseSync(
  process.env.DATABASE_PATH || path.join(__dirname, 'faculty-workload.sqlite')
);

database.exec(`
  CREATE TABLE IF NOT EXISTS schedule_datasets (
    id TEXT PRIMARY KEY,
    course_code TEXT NOT NULL,
    course_title TEXT NOT NULL,
    section TEXT NOT NULL,
    day_of_week TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    credit_units REAL NOT NULL,
    room_or_platform TEXT NOT NULL,
    modality_default TEXT NOT NULL
  )
`);

const scheduleSeedCourses = [
  ['CS 111', 'Introduction to Computing', 'BSCS 1A-DM'],
  ['TM INST 4', 'Data Science Analytics', 'BSTM 3B'],
  ['EMC 111', 'Introduction to Computing', 'BSEMC-DA 1A'],
  ['IT INST 3', 'Data Science Analytics', 'BSIT 3A-BPO'],
  ['MA INST 4', 'Data Science Analytics', 'BSMA 3A'],
  ['LM INST 4', 'Data Science Analytics', 'BSLM 3A'],
  ['ELS INST 3', 'Data Science Analytics', 'BA-ELS 3'],
  ['IT 210', 'Web Systems and Technologies', 'BSIT 2A'],
  ['CS 220', 'Data Structures and Algorithms', 'BSCS 2A'],
  ['IS 305', 'Systems Analysis and Design', 'BSIS 3A'],
  ['IT 315', 'Information Assurance', 'BSIT 3B'],
  ['CS 410', 'Capstone Project', 'BSCS 4A'],
] as const;
const scheduleSeedDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as const;
const scheduleSeedSlots = [
  ['07:00 AM', '10:00 AM'],
  ['08:00 AM', '11:00 AM'],
  ['10:00 AM', '01:00 PM'],
  ['01:00 PM', '04:00 PM'],
  ['02:00 PM', '05:00 PM'],
] as const;
const scheduleSeedInsert = database.prepare(`
  INSERT INTO schedule_datasets (
    id, course_code, course_title, section, day_of_week, start_time,
    end_time, credit_units, room_or_platform, modality_default
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
`);
const existingScheduleCount = database.prepare('SELECT COUNT(*) AS count FROM schedule_datasets').get() as { count: number };
if (existingScheduleCount.count === 0) {
  for (const [courseIndex, [courseCode, courseTitle, section]] of scheduleSeedCourses.entries()) {
    for (const [dayIndex, dayOfWeek] of scheduleSeedDays.entries()) {
      const [startTime, endTime] = scheduleSeedSlots[(courseIndex + dayIndex) % scheduleSeedSlots.length];
      scheduleSeedInsert.run(
        `demo-${String(courseIndex + 1).padStart(2, '0')}-${String(dayIndex + 1).padStart(2, '0')}`,
        courseCode,
        courseTitle,
        section,
        dayOfWeek,
        startTime,
        endTime,
        3,
        dayOfWeek === 'Friday' ? 'Google Classroom / Zoom' : `CCSICT Room ${200 + ((courseIndex + dayIndex) % 12)}`,
        dayOfWeek === 'Friday' ? 'Synchronous' : 'Face-to-Face'
      );
    }
  }
}

app.use(express.json({ limit: '10mb' }));

// Dedicated sanitizer to enforce universal header exclusions:
// NEVER generate, include, or output the texts "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned".
export function sanitizeUniversalExclusions<T>(data: T): T {
  if (typeof data === 'string') {
    let cleaned: string = data
      .replace(/\b1978\b/gi, '')
      .replace(/ISU\s+QUALITY\s+ASSURANCE/gi, '')
      .replace(/Seal\s+Aligned/gi, '');
    return cleaned as unknown as T;
  }
  if (Array.isArray(data)) {
    return data.map((item) => sanitizeUniversalExclusions(item)) as unknown as T;
  }
  if (data !== null && typeof data === 'object') {
    const copy: Record<string, any> = {};
    for (const [key, value] of Object.entries(data)) {
      copy[key] = sanitizeUniversalExclusions(value);
    }
    return copy as T;
  }
  return data;
}

// Shared Gemini client initializer with mandatory User-Agent
function getGeminiClient(): GoogleGenAI {
  const apiKey = process.env.GEMINI_API_KEY;
  if (!apiKey) {
    throw new Error('GEMINI_API_KEY is not configured in environment variables.');
  }
  return new GoogleGenAI({
    apiKey,
    httpOptions: {
      headers: {
        'User-Agent': 'aistudio-build',
      },
    },
  });
}

// Health check endpoint
app.get('/api/health', (req, res) => {
  res.json({
    status: 'ok',
    hasApiKey: !!process.env.GEMINI_API_KEY,
    timestamp: new Date().toISOString(),
  });
});

app.get('/api/admin/schedule-datasets', (_req, res) => {
  const rows = database.prepare(`
    SELECT
      id,
      course_code AS courseCode,
      course_title AS courseTitle,
      section,
      day_of_week AS dayOfWeek,
      start_time AS startTime,
      end_time AS endTime,
      credit_units AS creditUnits,
      room_or_platform AS roomOrPlatform,
      modality_default AS modalityDefault
    FROM schedule_datasets
    ORDER BY day_of_week, start_time, course_code
  `).all();
  res.json({ success: true, count: rows.length, data: rows });
});

// Module 1: Syllabus Generation
app.post('/api/generate/syllabus', async (req, res) => {
  try {
    const {
      courseCode,
      courseTitle,
      program,
      chedCmo,
      creditUnits,
      lectureHours,
      labHours,
      prerequisites,
      college,
      department,
      academicYear,
      semester,
      instructorName,
      focusTopics,
      institutionalOutcomesPreset,
    } = req.body;

    const ai = getGeminiClient();

    const prompt = `
You are the Outcomes-Based Education (OBE) generation engine for the Faculty Workload Portal.
Create a comprehensive, official OBE-compliant Course Syllabus adhering to CHED (Commission on Higher Education) Memorandum Orders (CMO) guidelines.

COURSE INFORMATION:
- Course Code: ${courseCode || 'IT 311'}
- Course Title: ${courseTitle || 'Web Systems and Technologies'}
- Program: ${program || 'Bachelor of Science in Information Technology'}
- Reference CHED CMO: ${chedCmo || 'CHED CMO No. 25, Series of 2015'}
- Credit Units: ${creditUnits || '3 Units'} (Lecture: ${lectureHours || 2} hrs/week, Lab: ${labHours || 3} hrs/week)
- Prerequisites: ${prerequisites || 'None'}
- College: ${college || 'College of Computing and Information Sciences'}
- Department: ${department || 'Department of Information Technology'}
- Term: ${semester || '1st Semester'}, Academic Year: ${academicYear || '2026-2027'}
- Instructor: ${instructorName || 'Faculty Member'}
- Specific Topic Directives / Faculty Notes: ${focusTopics || 'Provide standard modern industry curriculum with progressive week-by-week coverage from foundational to advanced capstone project.'}
- Institutional Context: ${institutionalOutcomesPreset || 'Isabela State University SMART-Green OBE Framework'}
- Vision: A model SMART-Green University with a global reputation for positive societal transformation.
- Mission: Developing lifelong and future-ready professionals empowered to become generators of knowledge and agents of positive change.
- Quality Policy Objectives: Academic excellence, research breakthroughs, sectoral development, globalization, student participation.
- Institutional Outcomes: IO1 - Innovator, IO2 - People-Oriented, IO3 - Entrepreneur, IO4 - Naturalist.
- Expected Graduate Attributes: GA1 Critical & Creative Thinker, GA2 Technology-Enabled Innovator, GA3 Socially Responsible Citizen, GA4 Ethical Professional, GA6 Leader, GA7 Problem Solver, GA8 Entrepreneur, GA9/GA10 Sustainability Practitioner.
- Classroom AI Policy (BOR No. 66, Series of 2026): Student assessment acceptable AI threshold shall not exceed 25%. Minor (26-50%), Moderate (51-75%), Severe (76-100%).
- Grading Formula: Grade = [TOTAL (Lec) * 0.6 + [TOTAL (Lab)] * 0.4].

CRITICAL EXCLUSIONS:
Never output, generate, or include the phrases "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned" in any header, title, or body.

REQUIRED OUTPUT STRUCTURE:
Generate a complete, fully populated OBE Syllabus structure containing:
1. Course Description (rigorous, OBE-compliant, referencing program goals and industry alignment)
2. Course Objectives (clear general aims)
3. Institutional Outcomes (IOs) (IO1: Innovator, IO2: People-Oriented, IO3: Entrepreneur, IO4: Naturalist)
4. Graduate Attributes (GAs) (GA1 to GA10)
5. Program Outcomes (POs) (aligned with the CHED CMO, e.g., PO1 to PO4/PO6)
6. Course Learning Outcomes (CLOs) (at least 4 measurable action verbs using Revised Bloom's Taxonomy, each mapped to POs with I/E/D indicators and GAs)
7. Assessment & Grading System (lecture 60%, lab 40%, formula Grade = [TOTAL (Lec) * 0.6 + [TOTAL (Lab)] * 0.4], formative 50%, summative 50%)
8. Course Plan: 18-week semester plan divided into clear weekly modules (Weeks 1 to 18, including Midterm Exam on Week 12 and Final Exam on Week 18).
   Each week must have:
   - weekNumber: number
   - weekRange: e.g. "Week 1", "Weeks 2-3"
   - topic: clear module/subject title (Unit I, Unit II, Unit III, Unit IV)
   - lilos: array of Lesson Intended Learning Outcomes (action-verb driven)
   - cloCodes: array of mapped CLO codes, e.g. ["CLO1", "CLO2"]
   - tlas: array of Teaching & Learning Activities with modality (Synchronous, Asynchronous, Face-to-Face, or Blended) and description
   - ats: array of Assessment Tasks (e.g. "Hands-on Lab Exercise #1", "Quizzes", "Rubric-based Code Review")
   - resources: array of learning materials/references
9. References: Category-based list (Textbooks, Journal Articles, Official Documentation, Web Resources).
`;

    const response = await ai.models.generateContent({
      model: 'gemini-3.8-flash',
      contents: prompt,
      config: {
        systemInstruction:
          'You are a senior academic curriculum specialist and OBE expert. Output strictly valid JSON conforming to the requested schema. Do NOT include Markdown formatting like ```json ... ``` around the JSON if responseMimeType is application/json. Adhere strictly to header exclusions: do NOT include "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned".',
        responseMimeType: 'application/json',
        responseSchema: {
          type: Type.OBJECT,
          properties: {
            headerInfo: {
              type: Type.OBJECT,
              properties: {
                courseCode: { type: Type.STRING },
                courseTitle: { type: Type.STRING },
                program: { type: Type.STRING },
                chedCmo: { type: Type.STRING },
                creditUnits: { type: Type.STRING },
                prerequisites: { type: Type.STRING },
                college: { type: Type.STRING },
                department: { type: Type.STRING },
                semester: { type: Type.STRING },
                academicYear: { type: Type.STRING },
                instructorName: { type: Type.STRING },
              },
              required: ['courseCode', 'courseTitle', 'program'],
            },
            courseDescription: { type: Type.STRING },
            courseObjectives: {
              type: Type.ARRAY,
              items: { type: Type.STRING },
            },
            institutionalOutcomes: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  code: { type: Type.STRING },
                  title: { type: Type.STRING },
                  description: { type: Type.STRING },
                },
                required: ['code', 'title', 'description'],
              },
            },
            graduateAttributes: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  code: { type: Type.STRING },
                  title: { type: Type.STRING },
                  description: { type: Type.STRING },
                },
                required: ['code', 'title', 'description'],
              },
            },
            programOutcomes: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  code: { type: Type.STRING },
                  statement: { type: Type.STRING },
                },
                required: ['code', 'statement'],
              },
            },
            courseLearningOutcomes: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  code: { type: Type.STRING },
                  statement: { type: Type.STRING },
                  poMapping: {
                    type: Type.ARRAY,
                    items: {
                      type: Type.OBJECT,
                      properties: {
                        poCode: { type: Type.STRING },
                        level: { type: Type.STRING, description: "I (Introduced), E (Enabled), or D (Demonstrated)" },
                      },
                      required: ['poCode', 'level'],
                    },
                  },
                  gaCodes: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                },
                required: ['code', 'statement'],
              },
            },
            assessmentGradingSystem: {
              type: Type.OBJECT,
              properties: {
                lectureWeight: { type: Type.NUMBER },
                labWeight: { type: Type.NUMBER },
                components: {
                  type: Type.ARRAY,
                  items: {
                    type: Type.OBJECT,
                    properties: {
                      name: { type: Type.STRING },
                      percentage: { type: Type.NUMBER },
                    },
                    required: ['name', 'percentage'],
                  },
                },
              },
              required: ['lectureWeight', 'components'],
            },
            coursePlan: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  weekNumber: { type: Type.INTEGER },
                  weekRange: { type: Type.STRING },
                  topic: { type: Type.STRING },
                  lilos: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                  cloCodes: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                  tlas: {
                    type: Type.ARRAY,
                    items: {
                      type: Type.OBJECT,
                      properties: {
                        modality: { type: Type.STRING },
                        activity: { type: Type.STRING },
                      },
                      required: ['modality', 'activity'],
                    },
                  },
                  ats: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                  resources: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                },
                required: ['weekNumber', 'weekRange', 'topic', 'lilos', 'tlas', 'ats'],
              },
            },
            references: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  category: { type: Type.STRING },
                  items: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                },
                required: ['category', 'items'],
              },
            },
          },
          required: [
            'headerInfo',
            'courseDescription',
            'courseObjectives',
            'programOutcomes',
            'courseLearningOutcomes',
            'coursePlan',
          ],
        },
      },
    });

    const parsed = JSON.parse(response.text || '{}');
    const sanitized = sanitizeUniversalExclusions(parsed);
    res.json({ success: true, data: sanitized });
  } catch (error: any) {
    console.error('Error generating syllabus:', error);
    res.status(500).json({
      success: false,
      error: error?.message || 'Failed to generate syllabus.',
    });
  }
});

// Module 2: Accomplishment Report (AR) Generation
app.post('/api/generate/accomplishment-report', async (req, res) => {
  try {
    const {
      facultyName,
      academicRank,
      department,
      college,
      period,
      reportType,
      submissionDate,
      rawLogs,
      activeCourses,
      committeeDuties,
      researchProjects,
      extensionActivities,
    } = req.body;

    const ai = getGeminiClient();

    const prompt = `
You are the Faculty Accomplishment Report (AR) synthesis engine.
Transform raw faculty logs and administrative records into a formal, highly articulate, action-oriented Accomplishment Report.

FACULTY METADATA:
- Faculty Name: ${facultyName || 'Faculty Member'}
- Academic Rank: ${academicRank || 'Assistant Professor II'}
- Department: ${department || 'Department of Information Technology'}
- College: ${college || 'College of Computing and Information Sciences'}
- Reporting Period: ${period || 'Current Month'}
- Report Type: ${reportType || 'Monthly'}
- Submission Date: ${submissionDate || new Date().toISOString().split('T')[0]}

RAW FACULTY LOGS & NOTES:
${rawLogs || 'Conducted classes regularly, administered quizzes, completed grading, held research consultation, attended faculty curriculum committee meeting, prepared WFH learning modules.'}

ADDITIONAL CONTEXT:
- Active Courses / Teaching Load: ${activeCourses || 'Standard academic load'}
- Committee / Admin Duties: ${committeeDuties || 'Curriculum committee, Capstone review panel'}
- Research / Creative Works: ${researchProjects || 'Ongoing paper drafts and publication monitoring'}
- Community / Extension Services: ${extensionActivities || 'Community tech literacy webinar mentoring'}

CRITICAL EXCLUSIONS:
Never output, generate, or include the phrases "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned" in any header, title, or body.

OBJECTIVES:
1. Synthesize brief, colloquial, or rough notes into formal, executive-grade academic accomplishments using strong action verbs (e.g., "Spearheaded", "Facilitated", "Formulated", "Appraised", "Administered").
2. Categorize all accomplishments strictly under the 4 Major Pillars of Faculty Workload:
   - Pillar I: Higher and Advanced Education Services (Instruction & Student Mentorship)
   - Pillar II: Research, Innovation, and Creative Endeavors
   - Pillar III: Extension Services and Community Engagement
   - Pillar IV: Support to Operations, Administrative Services, and Production
3. For each accomplishment item, provide:
   - itemDescription: formal narrative
   - targetOutputs: measurable physical or digital output (e.g., "Graded outputs for 45 students across 2 sections", "1 complete OBE syllabus document")
   - meansOfVerification (MOV): verifiable evidence (e.g., "LMS Gradebook Archive", "Attendance Sheet & Zoom Cloud Recording", "Signed Department Minutes")
   - timelineOrDate: timeframe or dates accomplished
   - remarks: status (e.g., "100% Completed", "Submitted on schedule")
4. Include an Executive Summary providing high-level synthesis of productivity.
5. Include Challenges Encountered & Strategic Interventions.
`;

    const response = await ai.models.generateContent({
      model: 'gemini-3.8-flash',
      contents: prompt,
      config: {
        systemInstruction:
          'You are a senior university quality assurance and faculty evaluation officer. Convert rough faculty notes into impeccably formatted, professional accomplishment reports. Output strictly valid JSON. Strict exclusion: NEVER output "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned".',
        responseMimeType: 'application/json',
        responseSchema: {
          type: Type.OBJECT,
          properties: {
            header: {
              type: Type.OBJECT,
              properties: {
                facultyName: { type: Type.STRING },
                academicRank: { type: Type.STRING },
                department: { type: Type.STRING },
                college: { type: Type.STRING },
                period: { type: Type.STRING },
                reportType: { type: Type.STRING },
                submissionDate: { type: Type.STRING },
              },
              required: ['facultyName', 'period', 'reportType'],
            },
            executiveSummary: { type: Type.STRING },
            pillars: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  pillarId: { type: Type.STRING },
                  pillarTitle: { type: Type.STRING },
                  description: { type: Type.STRING },
                  accomplishments: {
                    type: Type.ARRAY,
                    items: {
                      type: Type.OBJECT,
                      properties: {
                        itemDescription: { type: Type.STRING },
                        targetOutputs: { type: Type.STRING },
                        meansOfVerification: { type: Type.STRING },
                        timelineOrDate: { type: Type.STRING },
                        remarks: { type: Type.STRING },
                      },
                      required: ['itemDescription', 'targetOutputs', 'meansOfVerification'],
                    },
                  },
                },
                required: ['pillarId', 'pillarTitle', 'accomplishments'],
              },
            },
            challengesAndInterventions: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  challenge: { type: Type.STRING },
                  actionTaken: { type: Type.STRING },
                  impact: { type: Type.STRING },
                },
                required: ['challenge', 'actionTaken'],
              },
            },
            signatories: {
              type: Type.OBJECT,
              properties: {
                preparedBy: { type: Type.STRING },
                verifiedBy: { type: Type.STRING },
                approvedBy: { type: Type.STRING },
              },
              required: ['preparedBy'],
            },
          },
          required: ['header', 'executiveSummary', 'pillars', 'signatories'],
        },
      },
    });

    const parsed = JSON.parse(response.text || '{}');
    const sanitized = sanitizeUniversalExclusions(parsed);
    res.json({ success: true, data: sanitized });
  } catch (error: any) {
    console.error('Error generating accomplishment report:', error);
    res.status(500).json({
      success: false,
      error: error?.message || 'Failed to generate accomplishment report.',
    });
  }
});

// Module 3: Learner's Activity Report (LAR) Generation (WFH Focused)
app.post('/api/generate/learning-activity-report', async (req, res) => {
  try {
    const {
      wfhDate,
      dayOfWeek,
      facultyProfile,
      modality, // "Asynchronous" | "Synchronous" | "Blended WFH"
      semesterWeekNumber,
      scheduledClasses, // array of classes for that day
      syllabusContext, // syllabus data or topics for that week
      rawFacultyNotes,
    } = req.body;

    const ai = getGeminiClient();

    const prompt = `
You are the AI generation engine for the official "LEARNING ACTIVITY REPORT" (LAR) for faculty members on Work-From-Home (WFH) arrangement.
Your task is to produce a strictly structured, audit-ready, syllabus-aligned report for the selected WFH date.

INPUT PARAMETERS:
- Work-From-Home Date: ${wfhDate || '2026-11-06'} (${dayOfWeek || 'Friday'})
- Semester Week: Week ${semesterWeekNumber || 11}
- Modality: ${modality || 'Asynchronous WFH'}
- Faculty Profile:
  * Name: ${facultyProfile?.name || 'Prof. Faculty Member'}
  * Rank: ${facultyProfile?.rank || 'Assistant Professor II'}
  * College: ${facultyProfile?.college || 'College of Computing and Information Sciences'}
  * Department: ${facultyProfile?.department || 'Department of Information Technology'}
  * Academic Year: ${facultyProfile?.academicYear || '2026-2027'}, Term: ${facultyProfile?.semester || '1st Semester'}

SCHEDULED CLASSES FOR THIS SPECIFIC DAY (${dayOfWeek}):
${JSON.stringify(scheduledClasses || [], null, 2)}

CORRESPONDING SYLLABUS CONTEXT & TOPICS FOR THIS WEEK:
${JSON.stringify(syllabusContext || {}, null, 2)}

ADDITIONAL FACULTY LOG NOTES:
${rawFacultyNotes || 'Standard teaching schedule, conducted asynchronous LMS module, monitored student discussion board, recorded lecture snippet, assessed submitted laboratory exercises.'}

CRITICAL EXCLUSIONS:
Never output, generate, or include the phrases "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned" in any header, title, or body.

RULES FOR CONTENT DRAFTING:
1. Strict Format Adherence: Map directly to the institutional "LEARNING ACTIVITY REPORT" standard table.
2. Schedule Integration: Use the exact subjects, sections, time slots, and units from the provided schedule for this specific day.
3. Contextual AI Accomplishments:
   - For each class scheduled on this day, cross-reference with Week ${semesterWeekNumber || 11} of the Course Syllabus.
   - Generate specific, syllabus-aligned "TOPIC(S) COVERED".
   - Draft professional, action-oriented "ACCOMPLISHMENT(S)" tailored specifically to ${modality || 'Asynchronous'}:
     * If Asynchronous: focus on LMS module deployment, video lecture distribution, discussion prompts, asynchronous problem sets, formative assessments, student ticket/query resolution, submission verifications.
     * If Synchronous: focus on live interactive lectures, code-alongs, breakout room collaborations, synchronous Q&A, real-time polling, demonstration of practical tools.
4. Define concrete Means of Verification (MOV) for every class (e.g., "Google Classroom time-stamped activity log", "Google Drive submission folder link & screenshot", "Zoom cloud recording link & transcript", "Canvas rubric grading logs").
5. Include Student Engagement Metrics (e.g., attendance count, submission rate, LMS engagement percentage).
6. Provide a formal, comprehensive overall day summary and certification statement.
`;

    const response = await ai.models.generateContent({
      model: 'gemini-3.8-flash',
      contents: prompt,
      config: {
        systemInstruction:
          'You are an expert university registrar and distance-learning instructional supervisor. Output strictly structured JSON for the LEARNING ACTIVITY REPORT. Strict rule: NEVER generate or include "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned".',
        responseMimeType: 'application/json',
        responseSchema: {
          type: Type.OBJECT,
          properties: {
            header: {
              type: Type.OBJECT,
              properties: {
                reportTitle: { type: Type.STRING },
                facultyName: { type: Type.STRING },
                academicRank: { type: Type.STRING },
                college: { type: Type.STRING },
                department: { type: Type.STRING },
                academicYear: { type: Type.STRING },
                semester: { type: Type.STRING },
                wfhDate: { type: Type.STRING },
                dayOfWeek: { type: Type.STRING },
                modality: { type: Type.STRING },
                weekNumber: { type: Type.INTEGER },
              },
              required: ['reportTitle', 'facultyName', 'wfhDate', 'dayOfWeek', 'modality'],
            },
            classesReported: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  courseCode: { type: Type.STRING },
                  courseTitle: { type: Type.STRING },
                  section: { type: Type.STRING },
                  timeSchedule: { type: Type.STRING },
                  creditUnits: { type: Type.STRING },
                  platformUsed: { type: Type.STRING },
                  topicCovered: { type: Type.STRING },
                  intendedLearningOutcomes: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                  accomplishments: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                  studentEngagement: {
                    type: Type.OBJECT,
                    properties: {
                      enrolledStudents: { type: Type.NUMBER },
                      activeParticipants: { type: Type.NUMBER },
                      submissionCount: { type: Type.NUMBER },
                      remarks: { type: Type.STRING },
                    },
                    required: ['enrolledStudents', 'activeParticipants'],
                  },
                  meansOfVerification: {
                    type: Type.ARRAY,
                    items: { type: Type.STRING },
                  },
                  remarksAndObservations: { type: Type.STRING },
                },
                required: [
                  'courseCode',
                  'courseTitle',
                  'section',
                  'timeSchedule',
                  'platformUsed',
                  'topicCovered',
                  'accomplishments',
                  'meansOfVerification',
                ],
              },
            },
            nonTeachingActivities: {
              type: Type.ARRAY,
              items: {
                type: Type.OBJECT,
                properties: {
                  timeSlot: { type: Type.STRING },
                  activityType: { type: Type.STRING },
                  description: { type: Type.STRING },
                  outputOrMOV: { type: Type.STRING },
                },
                required: ['timeSlot', 'description', 'outputOrMOV'],
              },
            },
            overallDaySummary: { type: Type.STRING },
            certificationText: { type: Type.STRING },
            signatories: {
              type: Type.OBJECT,
              properties: {
                facultyName: { type: Type.STRING },
                departmentChair: { type: Type.STRING },
                collegeDean: { type: Type.STRING },
              },
              required: ['facultyName'],
            },
          },
          required: ['header', 'classesReported', 'overallDaySummary', 'signatories'],
        },
      },
    });

    const parsed = JSON.parse(response.text || '{}');
    const sanitized = sanitizeUniversalExclusions(parsed);
    res.json({ success: true, data: sanitized });
  } catch (error: any) {
    console.error('Error generating LAR:', error);
    res.status(500).json({
      success: false,
      error: error?.message || 'Failed to generate Learner Activity Report.',
    });
  }
});

// Helper for AI Chat / Custom Refining of any generated report
app.post('/api/generate/refine', async (req, res) => {
  try {
    const { documentType, currentData, instructions } = req.body;
    const ai = getGeminiClient();

    const prompt = `
You are the AI generation engine for the Faculty Workload Portal.
Please refine and adjust the following ${documentType || 'academic document'} according to the user's instructions:

USER INSTRUCTION:
${instructions}

CURRENT DOCUMENT DATA (JSON):
${JSON.stringify(currentData, null, 2)}

CRITICAL EXCLUSIONS:
Never output, generate, or include the phrases "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned".

Return the updated document in valid JSON matching the same schema structure.
`;

    const response = await ai.models.generateContent({
      model: 'gemini-3.8-flash',
      contents: prompt,
      config: {
        systemInstruction:
          'Refine the academic document JSON accurately while maintaining schema compatibility. Strict exclusion: NEVER output "1978", "ISU QUALITY ASSURANCE", or "Seal Aligned".',
        responseMimeType: 'application/json',
      },
    });

    const parsed = JSON.parse(response.text || '{}');
    const sanitized = sanitizeUniversalExclusions(parsed);
    res.json({ success: true, data: sanitized });
  } catch (error: any) {
    console.error('Error refining document:', error);
    res.status(500).json({
      success: false,
      error: error?.message || 'Failed to refine document.',
    });
  }
});

// Setup Vite middleware in dev or static files in production
async function startServer() {
  const isProd = process.env.NODE_ENV === 'production';

  if (!isProd) {
    const { createServer: createViteServer } = await import('vite');
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: 'spa',
    });
    app.use(vite.middlewares);
  } else {
    app.use(express.static(path.resolve(__dirname, 'dist')));
    app.get('*', (req, res) => {
      res.sendFile(path.resolve(__dirname, 'dist', 'index.html'));
    });
  }

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`Faculty Workload Portal server running on port ${PORT}`);
  });
}

startServer().catch((err) => {
  console.error('Failed to start server:', err);
  process.exit(1);
});
