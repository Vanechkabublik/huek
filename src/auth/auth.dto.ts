import {IsEmail, IsNotEmpty} from "class-validator";

export class RegisterDto {
    @IsNotEmpty()
    name: string;

    @IsEmail()
    email: string;

    @IsNotEmpty()
    password: string;
}

export class LoginDto {
    @IsEmail()
    email: string;

    @IsNotEmpty()
    password: string;
}