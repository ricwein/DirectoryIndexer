export default class {
    constructor({aTime, cTime, fileType, filename, id, mTime, mime, type}) {
        this.fileType = fileType;
        this.id = id;
        this.filename = filename;
        this.type = type;
        this.mime = mime;
        this.mTime = mTime;
        this.aTime = aTime;
        this.cTime = cTime;
    }
}
