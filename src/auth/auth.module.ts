import { Module } from '@nestjs/common';
import { AuthService } from './auth.service';
import { AuthController } from './auth.controller';
import {TypeOrmModule} from "@nestjs/typeorm";
import {User} from "../user/user.entity";
import {JwtModule} from "@nestjs/jwt";
import {ConfigModule, ConfigService} from "@nestjs/config";

@Module({
  imports: [
     TypeOrmModule.forFeature([User]),
    JwtModule.registerAsync({
      imports: [ConfigModule],
      inject: [ConfigService],
      useFactory: (configService: ConfigService) => ({
        secret: configService.getOrThrow('JWT_SECRET'),
        signOptions: { expiresIn: configService.getOrThrow('JWT_EXP_AT') },
      }),
    })
  ],
  controllers: [AuthController],
  providers: [AuthService],
})
export class AuthModule {}
